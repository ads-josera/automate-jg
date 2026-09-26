/**
 * @file
 * Shows why a constancia is in error from its badge in the list.
 *
 * The bubble is rendered next to the badge (so screen readers get it through
 * aria-describedby) and positioned "fixed" on open: the table scrolls inside
 * its own box, which would otherwise clip it. Opens on hover, keyboard focus
 * or tap (touch has no hover); closes on leave, Escape, scroll or a tap
 * elsewhere.
 */
((Drupal, once) => {
  const GAP = 8;
  const GUTTER = 16;
  let current = null;

  const close = () => {
    if (!current) {
      return;
    }
    current.trigger.setAttribute('aria-expanded', 'false');
    current.bubble.classList.remove('is-open');
    current = null;
  };

  const open = (trigger, bubble) => {
    if (current && current.trigger === trigger) {
      return;
    }
    close();
    bubble.classList.add('is-open');
    trigger.setAttribute('aria-expanded', 'true');
    const badge = trigger.getBoundingClientRect();
    const box = bubble.getBoundingClientRect();
    const below = badge.bottom + GAP + box.height <= window.innerHeight - GUTTER;
    const top = below ? badge.bottom + GAP : Math.max(GUTTER, badge.top - GAP - box.height);
    const left = Math.min(Math.max(GUTTER, badge.left), window.innerWidth - GUTTER - box.width);
    bubble.style.top = `${Math.round(top)}px`;
    bubble.style.left = `${Math.round(Math.max(GUTTER, left))}px`;
    current = { trigger, bubble };
  };

  Drupal.behaviors.aseguramientoStatusTooltip = {
    attach(context) {
      once('aa-status-tip', '.aseguramiento-status-tip', context).forEach((tip) => {
        const trigger = tip.querySelector('.aseguramiento-status-tip__trigger');
        const bubble = tip.querySelector('.aseguramiento-status-tip__bubble');
        trigger.addEventListener('mouseenter', () => open(trigger, bubble));
        trigger.addEventListener('focus', () => open(trigger, bubble));
        // Always opens: a tap first fires the emulated mouseenter/focus, so
        // toggling here would close it right away. A tap elsewhere closes it.
        trigger.addEventListener('click', (event) => {
          event.stopPropagation();
          open(trigger, bubble);
        });
        tip.addEventListener('mouseleave', () => {
          if (document.activeElement !== trigger) {
            close();
          }
        });
        trigger.addEventListener('blur', close);
      });

      once('aa-status-tip-global', 'body', context).forEach(() => {
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            close();
          }
        });
        document.addEventListener('click', close);
        window.addEventListener('scroll', close, { capture: true, passive: true });
        window.addEventListener('resize', close);
      });
    },
  };
})(Drupal, once);
