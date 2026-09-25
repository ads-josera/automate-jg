/**
 * @file
 * "Vista previa" of the editable emails in the automation settings form.
 *
 * Sends what is currently typed (saved or not) to the preview endpoint, which
 * renders it with the same code that sends the real email, and shows the
 * result in a sandboxed iframe (no scripts, isolated from the admin page).
 */
((Drupal, drupalSettings, once) => {
  // Form fields each preview reads; must match EmailPreviewController::EDITABLE.
  const FIELDS = {
    client: ['email_reply_subject', 'email_reply_body', 'email_reply_is_html'],
    team: ['notification_subject', 'notification_body'],
    batch: ['batch_subject_ok', 'batch_subject_partial', 'batch_subject_errors', 'batch_body'],
  };

  let tokenPromise = null;
  const csrfToken = () => {
    tokenPromise = tokenPromise || fetch(drupalSettings.aseguramientoEmailPreview.tokenUrl, { credentials: 'same-origin' }).then((r) => r.text());
    return tokenPromise;
  };

  const readValues = (form, template) => {
    const values = {};
    FIELDS[template].forEach((name) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (field) {
        values[name] = field.type === 'checkbox' ? field.checked : field.value;
      }
    });
    return values;
  };

  const render = (result, data) => {
    result.replaceChildren();
    const subject = document.createElement('p');
    subject.className = 'aa-email-preview__subject';
    const label = document.createElement('strong');
    label.textContent = `${Drupal.t('Asunto')}: `;
    subject.append(label, document.createTextNode(data.subject));
    result.append(subject);
    if (data.note) {
      const note = document.createElement('p');
      note.className = 'aa-email-preview__note';
      note.textContent = data.note;
      result.append(note);
    }
    const frame = document.createElement('iframe');
    frame.className = 'aa-email-preview__frame';
    frame.title = Drupal.t('Vista previa del correo');
    frame.setAttribute('sandbox', '');
    frame.srcdoc = data.html;
    result.append(frame);
    result.hidden = false;
  };

  Drupal.behaviors.aseguramientoEmailPreview = {
    attach(context) {
      once('aa-email-preview', '[data-aa-email-preview]', context).forEach((widget) => {
        const template = widget.dataset.aaEmailPreview;
        const button = widget.querySelector('.aa-email-preview__button');
        const status = widget.querySelector('.aa-email-preview__status');
        const result = widget.querySelector('.aa-email-preview__result');
        const form = widget.closest('form');

        button.addEventListener('click', async () => {
          button.disabled = true;
          status.textContent = Drupal.t('Generando vista previa…');
          try {
            const response = await fetch(drupalSettings.aseguramientoEmailPreview.url, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await csrfToken() },
              body: JSON.stringify({ template, values: readValues(form, template) }),
            });
            if (!response.ok) {
              throw new Error(String(response.status));
            }
            render(result, await response.json());
            status.textContent = Drupal.t('Vista previa con datos de ejemplo. Refleja lo escrito aunque no lo hayas guardado.');
            button.textContent = Drupal.t('Actualizar vista previa');
          }
          catch (error) {
            status.textContent = Drupal.t('No se pudo generar la vista previa. Revisa tu conexión e inténtalo de nuevo.');
          }
          finally {
            button.disabled = false;
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
