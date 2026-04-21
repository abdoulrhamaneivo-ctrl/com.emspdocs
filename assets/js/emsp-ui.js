(function (window, document) {
    'use strict';

    function getSwal() {
        return window.Swal && typeof window.Swal.fire === 'function' ? window.Swal : null;
    }

    function normalizeType(type) {
        var map = {
            danger: 'error',
            error: 'error',
            success: 'success',
            info: 'info',
            warning: 'warning',
            question: 'question'
        };
        return map[String(type || '').toLowerCase()] || 'info';
    }

    function getConfirmTheme() {
        return {
            customClass: {
                popup: 'emsp-swal-popup',
                title: 'emsp-swal-title',
                htmlContainer: 'emsp-swal-text',
                confirmButton: 'emsp-swal-confirm',
                cancelButton: 'emsp-swal-cancel'
            },
            buttonsStyling: false,
            reverseButtons: true,
            focusCancel: true
        };
    }

    function getToastTheme() {
        return {
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4200,
            timerProgressBar: true,
            customClass: {
                popup: 'emsp-swal-toast',
                title: 'emsp-swal-toast-title',
                htmlContainer: 'emsp-swal-toast-text'
            }
        };
    }

    function toHtml(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function buildActionFooter(actionUrl, actionLabel) {
        if (!actionUrl || !actionLabel) {
            return '';
        }
        return '<a class="emsp-swal-link" href="' + toHtml(actionUrl) + '">' + toHtml(actionLabel) + '</a>';
    }

    function flashPayload() {
        var node = document.getElementById('emsp-flash-payload');
        if (!node) {
            return [];
        }
        try {
            var payload = JSON.parse(node.textContent || '[]');
            return Array.isArray(payload) ? payload : [];
        } catch (error) {
            return [];
        }
    }

    function showFallbackMessage(type, title, message) {
        var prefix = title ? title + ' - ' : '';
        window.alert(prefix + (message || 'Une action a ete effectuee.'));
    }

    function showMessage(type, options) {
        options = options || {};
        var normalized = normalizeType(type);
        var swal = getSwal();
        var title = options.title || '';
        var text = options.text || options.message || '';
        var footer = buildActionFooter(options.actionUrl, options.actionLabel);
        var isToast = options.toast !== false && !footer && normalized !== 'error';

        if (!swal) {
            showFallbackMessage(normalized, title, text);
            return Promise.resolve({ isConfirmed: true });
        }

        if (isToast) {
            return swal.fire(Object.assign({}, getToastTheme(), {
                icon: normalized,
                title: title || text,
                text: title ? text : '',
                footer: footer || undefined
            }));
        }

        return swal.fire(Object.assign({}, getConfirmTheme(), {
            icon: normalized,
            title: title || 'Information',
            text: text || '',
            footer: footer || undefined,
            confirmButtonText: options.confirmText || 'Fermer'
        }));
    }

    function setButtonLoading(button, isLoading, options) {
        if (!button) {
            return;
        }

        options = options || {};
        var loadingText = options.text || button.getAttribute('data-loading-text') || 'Chargement...';
        var originalHtml = button.getAttribute('data-emsp-original-html');

        if (isLoading) {
            if (!originalHtml) {
                button.setAttribute('data-emsp-original-html', button.innerHTML);
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.classList.add('emsp-btn-loading');
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><span>' + toHtml(loadingText) + '</span>';
            return;
        }

        if (originalHtml) {
            button.innerHTML = originalHtml;
        }
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.classList.remove('emsp-btn-loading');
        button.removeAttribute('data-emsp-original-html');
    }

    function request(url, options) {
        options = options || {};
        var headers = new window.Headers(options.headers || {});
        if (!headers.has('X-Requested-With')) {
            headers.set('X-Requested-With', 'XMLHttpRequest');
        }
        if (!headers.has('Accept')) {
            headers.set('Accept', 'application/json');
        }

        var fetchOptions = Object.assign({}, options, {
            credentials: options.credentials || 'include',
            headers: headers
        });

        return window.fetch(url, fetchOptions).then(function (response) {
            return response.text().then(function (text) {
                var payload = {};
                try {
                    payload = text ? JSON.parse(text) : {};
                } catch (error) {
                    payload = {};
                }
                return {
                    response: response,
                    payload: payload,
                    text: text
                };
            });
        });
    }

    function submitAjaxForm(form, options) {
        options = options || {};
        if (!form) {
            return Promise.reject(new Error('Formulaire introuvable'));
        }

        var submitter = options.submitter || null;
        var formData = options.formData || new window.FormData(form);
        var method = String(options.method || form.getAttribute('method') || 'POST').toUpperCase();
        var action = options.url || form.getAttribute('action') || window.location.href;

        if (submitter) {
            setButtonLoading(submitter, true, options.loading || {});
        }

        return request(action, {
            method: method,
            body: formData
        }).then(function (result) {
            if (submitter) {
                setButtonLoading(submitter, false);
            }
            return result;
        }).catch(function (error) {
            if (submitter) {
                setButtonLoading(submitter, false);
            }
            throw error;
        });
    }

    function confirmActionPromise(button) {
        if (!button) {
            return Promise.resolve(false);
        }

        var title = button.getAttribute('data-confirm') || 'Confirmer cette action ?';
        var detail = button.getAttribute('data-confirm-detail') || '';
        var type = normalizeType(button.getAttribute('data-confirm-type') || 'warning');
        var confirmText = button.getAttribute('data-confirm-ok') || 'Confirmer';
        var cancelText = button.getAttribute('data-confirm-cancel') || 'Annuler';
        var swal = getSwal();

        if (!swal) {
            return Promise.resolve(window.confirm(detail ? title + '\n\n' + detail : title));
        }

        return swal.fire(Object.assign({}, getConfirmTheme(), {
            icon: type,
            title: title,
            text: detail,
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: cancelText
        })).then(function (result) {
            return !!(result && result.isConfirmed);
        });
    }

    function legacyConfirmAction(event, button) {
        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        confirmActionPromise(button).then(function (confirmed) {
            if (!confirmed) {
                return;
            }

            var form = button && typeof button.closest === 'function' ? button.closest('form') : null;
            var href = button ? (button.getAttribute('data-href') || button.getAttribute('href')) : '';
            if (form) {
                form.submit();
                return;
            }
            if (href) {
                window.location.href = href;
            }
        });

        return false;
    }

    function bindAutoSubmitForms(root) {
        (root || document).querySelectorAll('form[data-emsp-submit]').forEach(function (form) {
            if (form.dataset.emspSubmitBound === '1') {
                return;
            }
            form.dataset.emspSubmitBound = '1';
            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented) {
                    return;
                }
                var submitter = event.submitter || form.querySelector('[type="submit"]');
                if (!submitter) {
                    return;
                }
                setButtonLoading(submitter, true);
            });
        });
    }

    function bindConfirmTriggers(root) {
        (root || document).querySelectorAll('[data-emsp-confirm-auto="1"]').forEach(function (button) {
            if (button.dataset.emspConfirmBound === '1') {
                return;
            }
            button.dataset.emspConfirmBound = '1';
            button.addEventListener('click', function (event) {
                legacyConfirmAction(event, button);
            });
        });
    }

    function initSelects(root) {
        var $ = window.jQuery;
        if (!$ || !$.fn || !$.fn.select2) {
            return;
        }

        (root || document).querySelectorAll('select[data-emsp-select2]').forEach(function (select) {
            if (select.dataset.emspSelect2Ready === '1') {
                return;
            }

            var placeholder = select.getAttribute('data-emsp-select2-placeholder') || '';
            var parentModal = select.closest('.modal');
            var config = {
                width: '100%',
                placeholder: placeholder || undefined,
                dropdownAutoWidth: true
            };

            if (parentModal) {
                config.dropdownParent = $(parentModal);
            }

            $(select).select2(config);
            select.dataset.emspSelect2Ready = '1';
        });
    }

    function replayFlashMessages() {
        var flashes = flashPayload();
        if (!flashes.length) {
            return;
        }

        flashes.reduce(function (chain, flash) {
            return chain.then(function () {
                return showMessage(flash.type || 'info', {
                    title: flash.title || '',
                    text: flash.message || '',
                    actionUrl: flash.action_url || '',
                    actionLabel: flash.action_label || '',
                    toast: !(flash.action_url || '').trim()
                });
            });
        }, Promise.resolve());
    }

    function resetBusyButtons() {
        document.querySelectorAll('.emsp-btn-loading').forEach(function (button) {
            setButtonLoading(button, false);
        });
    }

    function init(root) {
        bindAutoSubmitForms(root || document);
        bindConfirmTriggers(root || document);
        initSelects(root || document);
    }

    window.emspUI = {
        confirm: confirmActionPromise,
        showSuccess: function (title, text, options) {
            return showMessage('success', Object.assign({}, options || {}, { title: title, text: text }));
        },
        showError: function (title, text, options) {
            return showMessage('error', Object.assign({}, options || {}, { title: title, text: text, toast: false }));
        },
        showInfo: function (title, text, options) {
            return showMessage('info', Object.assign({}, options || {}, { title: title, text: text }));
        },
        toast: function (type, title, text, options) {
            return showMessage(type, Object.assign({}, options || {}, { title: title, text: text }));
        },
        setButtonLoading: setButtonLoading,
        submitAjaxForm: submitAjaxForm,
        request: request,
        init: init,
        initSelects: initSelects
    };

    window.confirmAction = legacyConfirmAction;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
            replayFlashMessages();
        });
    } else {
        init(document);
        replayFlashMessages();
    }

    document.addEventListener('shown.bs.modal', function (event) {
        init(event.target || document);
    });

    window.addEventListener('pageshow', resetBusyButtons);
})(window, document);


