(function (Drupal, once, drupalSettings) {
  Drupal.behaviors.aseguramientoTemplateBuilder = {
    attach(context) {
      once('aseguramiento-template-builder', '.aa-builder', context).forEach((builder) => {
        const endpoint = drupalSettings.aseguramientoAutomation?.saveUrl;
        const token = drupalSettings.aseguramientoAutomation?.csrfToken;
        const page = builder.querySelector('.aa-builder__page');
        const palette = builder.querySelector('.aa-builder__palette');
        const saveButton = builder.querySelector('.aa-builder__save-fields');
        const saveStatus = builder.querySelector('.aa-builder__save-status');

        const payload = () => Array.from(builder.querySelectorAll('.aa-builder__field')).map((item) => ({
          field: item.dataset.field,
          page: parseInt(item.dataset.page || '1', 10),
          x: parseFloat(item.style.left || '0'),
          y: parseFloat(item.style.top || '0'),
          font: item.dataset.font || 'helvetica',
          size: parseFloat(item.dataset.size || '10'),
          color: item.dataset.color || '#000000',
          align: item.dataset.align || 'L',
          width: parseFloat(item.dataset.width || '0'),
          multiline: item.dataset.multiline === '1',
          format: item.dataset.format || '',
        }));

        const save = async () => {
          if (!endpoint) {
            return;
          }
          if (saveStatus) {
            saveStatus.textContent = 'Guardando...';
          }
          const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': token || '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ mappings: payload() }),
          });
          if (!response.ok) {
            const message = await response.text();
            throw new Error(message || 'No se pudieron guardar las posiciones.');
          }
          if (saveStatus) {
            saveStatus.textContent = 'Guardado';
          }
        };

        const attachField = (field) => {
          let origin = null;

          field.addEventListener('pointerdown', (event) => {
            if (event.target.closest('.aa-builder__remove-field')) {
              return;
            }
            origin = {
              pointerId: event.pointerId,
              startX: event.clientX,
              startY: event.clientY,
              left: parseFloat(field.style.left || '0'),
              top: parseFloat(field.style.top || '0'),
            };
            field.setPointerCapture(event.pointerId);
          });

          field.addEventListener('pointermove', (event) => {
            if (!origin || origin.pointerId !== event.pointerId) {
              return;
            }
            field.style.left = `${Math.max(0, origin.left + event.clientX - origin.startX)}px`;
            field.style.top = `${Math.max(0, origin.top + event.clientY - origin.startY)}px`;
          });

          field.addEventListener('pointerup', async (event) => {
            if (!origin || origin.pointerId !== event.pointerId || !endpoint) {
              origin = null;
              return;
            }
            origin = null;
            try {
              await save();
            }
            catch (error) {
              if (saveStatus) {
                saveStatus.textContent = 'Error al guardar. Recarga la página.';
              }
            }
          });

          field.addEventListener('dblclick', async () => {
            const button = palette?.querySelector(`[data-field="${field.dataset.field}"]`);
            field.remove();
            if (button) {
              button.disabled = false;
            }
            try {
              await save();
            }
            catch (error) {
              if (saveStatus) {
                saveStatus.textContent = 'Error al guardar. Recarga la página.';
              }
            }
          });
        };

        Array.from(builder.querySelectorAll('.aa-builder__field')).forEach(attachField);

        palette?.addEventListener('click', async (event) => {
          const button = event.target.closest('.aa-builder__add-field');
          if (!button || button.disabled || !page) {
            return;
          }

          const field = document.createElement('div');
          field.className = 'aa-builder__field';
          field.dataset.field = button.dataset.field;
          field.dataset.page = '1';
          field.dataset.font = 'helvetica';
          field.dataset.size = '10';
          field.dataset.color = '#000000';
          field.dataset.align = 'L';
          field.dataset.width = '160';
          field.dataset.multiline = '0';
          field.dataset.format = '';
          field.style.left = '40px';
          field.style.top = '40px';
          field.style.fontSize = '10px';
          field.style.color = '#000000';
          field.textContent = `{{ ${button.dataset.field} }}`;
          page.appendChild(field);
          button.disabled = true;
          attachField(field);
          try {
            await save();
          }
          catch (error) {
            if (saveStatus) {
              saveStatus.textContent = 'Error al guardar. Recarga la página.';
            }
          }
        });

        builder.querySelector('.aa-builder__clear-fields')?.addEventListener('click', async () => {
          Array.from(builder.querySelectorAll('.aa-builder__field')).forEach((field) => field.remove());
          Array.from(builder.querySelectorAll('.aa-builder__add-field')).forEach((button) => {
            button.disabled = false;
          });
          try {
            await save();
          }
          catch (error) {
            if (saveStatus) {
              saveStatus.textContent = 'Error al guardar. Recarga la página.';
            }
          }
        });

        saveButton?.addEventListener('click', async () => {
          try {
            await save();
          }
          catch (error) {
            if (saveStatus) {
              saveStatus.textContent = 'Error al guardar. Recarga la página.';
            }
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
