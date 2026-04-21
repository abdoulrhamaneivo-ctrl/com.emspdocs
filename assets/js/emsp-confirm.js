/**
 * SystÃ¨me de confirmation Ã©lÃ©gant pour les actions dangereuses.
 * Usage HTML :
 * <button data-confirm="Supprimer ce document ?"
 *         data-confirm-detail="Cette action est irrÃ©versible."
 *         data-confirm-type="danger"
 *         data-confirm-ok="Oui, supprimer"
 *         onclick="confirmAction(event, this)">
 * Supprimer
 * </button>
 */
function confirmAction(event, btn) {
    event.preventDefault();

    var title   = btn.dataset.confirm || 'Confirmer cette action ?';
    var detail  = btn.dataset.confirmDetail || '';
    var type    = btn.dataset.confirmType || 'warning'; // danger|warning|info
    var okText  = btn.dataset.confirmOk || 'Confirmer';
    var form    = btn.closest('form');
    var href    = btn.dataset.href || btn.getAttribute('href');

    var icons = {
        danger  : 'bi-trash3-fill',
        warning : 'bi-exclamation-triangle-fill',
        info    : 'bi-question-circle-fill'
    };
    var colors = {
        danger  : '#ef4444',
        warning : '#f59e0b',
        info    : '#3b82f6'
    };
    var btnClasses = {
        danger  : 'btn-danger',
        warning : 'btn-warning',
        info    : 'btn-primary'
    };

    var existing = document.getElementById('emsp-confirm-modal');
    if (existing) existing.remove();

    var modal = document.createElement('div');
    modal.id = 'emsp-confirm-modal';
    modal.innerHTML = `
        <div class="modal-backdrop fade show" style="z-index:9998;"></div>
        <div class="modal fade show d-block" style="z-index:9999;" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,.2);">
              <div class="modal-body text-center p-4">
                <div style="font-size:2.5rem;margin-bottom:.75rem;">
                  <i class="bi ${icons[type] || icons.warning}" style="color:${colors[type] || colors.warning};"></i>
                </div>
                <h5 class="fw-bold mb-2">${escHtml(title)}</h5>
                ${detail ? `<p class="text-muted small mb-3">${escHtml(detail)}</p>` : ''}
                <div class="d-flex gap-2 justify-content-center mt-3">
                  <button class="btn btn-outline-secondary" id="emsp-confirm-cancel">
                    Annuler
                  </button>
                  <button class="btn ${btnClasses[type] || btnClasses.warning}" id="emsp-confirm-ok">
                    ${escHtml(okText)}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>`;

    document.body.appendChild(modal);

    document.getElementById('emsp-confirm-cancel')
        .addEventListener('click', function() {
            modal.remove();
        });

    document.getElementById('emsp-confirm-ok')
        .addEventListener('click', function() {
            modal.remove();
            if (form) {
                form.submit();
            } else if (href) {
                window.location.href = href;
            }
        });
}

function escHtml(str) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}


