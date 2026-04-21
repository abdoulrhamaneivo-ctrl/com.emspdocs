(function () {
    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function ensureImageClasses(img) {
        if (!img.classList.contains('emsp-rich-img')) {
            img.classList.add('emsp-rich-img');
        }
        if (!img.classList.contains('emsp-rich-img-left')
            && !img.classList.contains('emsp-rich-img-center')
            && !img.classList.contains('emsp-rich-img-right')) {
            img.classList.add('emsp-rich-img-center');
        }
        img.style.maxWidth = '100%';
        img.style.height = 'auto';
    }

    function applyAlignment(img, align) {
        ensureImageClasses(img);
        img.classList.remove('emsp-rich-img-left', 'emsp-rich-img-center', 'emsp-rich-img-right');
        img.classList.add('emsp-rich-img-' + align);
    }

    function readEditorWidth(editorRoot) {
        var rect = editorRoot.getBoundingClientRect();
        return Math.max(320, Math.round(rect.width || 0));
    }

    function createButton(label, innerHtml) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'emsp-quill-image-btn';
        button.setAttribute('aria-label', label);
        button.title = label;
        button.innerHTML = innerHtml;
        return button;
    }

    window.emspAttachQuillImageTools = function (quill, options) {
        if (!quill || !quill.root) {
            return null;
        }

        options = options || {};
        var editorRoot = quill.root;
        var wrapper = quill.container && quill.container.parentElement ? quill.container.parentElement : editorRoot.parentElement;
        if (!wrapper) {
            return null;
        }
        if (getComputedStyle(wrapper).position === 'static') {
            wrapper.style.position = 'relative';
        }

        var state = {
            activeImg: null,
            resizing: false,
            startX: 0,
            startWidth: 0
        };

        var overlay = document.createElement('div');
        overlay.className = 'emsp-quill-image-overlay';
        overlay.hidden = true;

        var toolbar = document.createElement('div');
        toolbar.className = 'emsp-quill-image-toolbar';

        var leftBtn = createButton('Aligner a gauche', '<i class="bi bi-text-left"></i>');
        var centerBtn = createButton('Centrer', '<i class="bi bi-text-center"></i>');
        var rightBtn = createButton('Aligner a droite', '<i class="bi bi-text-right"></i>');
        var upBtn = createButton('Monter l image', '<i class="bi bi-arrow-up"></i>');
        var downBtn = createButton('Descendre l image', '<i class="bi bi-arrow-down"></i>');
        var smallBtn = createButton('Taille compacte', '<span>S</span>');
        var mediumBtn = createButton('Taille moyenne', '<span>M</span>');
        var largeBtn = createButton('Grande taille', '<span>L</span>');

        var widthLabel = document.createElement('span');
        widthLabel.className = 'emsp-quill-image-size';
        widthLabel.textContent = '';

        var resizeHandle = document.createElement('button');
        resizeHandle.type = 'button';
        resizeHandle.className = 'emsp-quill-image-handle';
        resizeHandle.setAttribute('aria-label', 'Redimensionner l image');
        resizeHandle.title = 'Glisser pour redimensionner';

        toolbar.appendChild(leftBtn);
        toolbar.appendChild(centerBtn);
        toolbar.appendChild(rightBtn);
        toolbar.appendChild(upBtn);
        toolbar.appendChild(downBtn);
        toolbar.appendChild(smallBtn);
        toolbar.appendChild(mediumBtn);
        toolbar.appendChild(largeBtn);
        toolbar.appendChild(widthLabel);
        overlay.appendChild(toolbar);
        overlay.appendChild(resizeHandle);
        wrapper.appendChild(overlay);

        function updateWidthLabel() {
            if (!state.activeImg) {
                widthLabel.textContent = '';
                return;
            }
            widthLabel.textContent = Math.round(state.activeImg.getBoundingClientRect().width) + ' px';
        }

        function positionOverlay() {
            if (!state.activeImg) {
                overlay.hidden = true;
                return;
            }

            var wrapperRect = wrapper.getBoundingClientRect();
            var imgRect = state.activeImg.getBoundingClientRect();
            overlay.style.left = (imgRect.left - wrapperRect.left - 6) + 'px';
            overlay.style.top = (imgRect.top - wrapperRect.top - 6) + 'px';
            overlay.style.width = (imgRect.width + 12) + 'px';
            overlay.style.height = (imgRect.height + 12) + 'px';
            updateWidthLabel();
            overlay.hidden = false;
        }

        function selectImage(img) {
            if (!img || !editorRoot.contains(img)) {
                return;
            }
            ensureImageClasses(img);
            state.activeImg = img;
            positionOverlay();
        }

        function clearSelection() {
            state.activeImg = null;
            overlay.hidden = true;
            state.resizing = false;
        }

        function insertUploadedImage(url) {
            var range = quill.getSelection(true);
            var index = range ? range.index : quill.getLength();
            quill.insertEmbed(index, 'image', url, 'user');
            quill.setSelection(index + 1, 0, 'silent');

            window.requestAnimationFrame(function () {
                var images = editorRoot.querySelectorAll('img');
                var img = images.length ? images[images.length - 1] : null;
                if (img) {
                    ensureImageClasses(img);
                    applyAlignment(img, 'center');
                    selectImage(img);
                }
            });
        }

        function closestMovableBlock(img) {
            if (!img) {
                return null;
            }
            var block = img.closest('p, div, figure, li, blockquote');
            if (!block || block === editorRoot || !editorRoot.contains(block)) {
                return img;
            }
            return block;
        }

        function moveImage(direction) {
            if (!state.activeImg) {
                return;
            }

            var block = closestMovableBlock(state.activeImg);
            if (!block || !block.parentNode) {
                return;
            }

            if (direction < 0) {
                var prev = block.previousElementSibling;
                if (prev) {
                    block.parentNode.insertBefore(block, prev);
                }
            } else {
                var next = block.nextElementSibling;
                if (next) {
                    block.parentNode.insertBefore(next, block);
                }
            }

            quill.update('silent');
            window.requestAnimationFrame(positionOverlay);
        }

        function applyPresetWidth(ratio) {
            if (!state.activeImg) {
                return;
            }
            var editorWidth = readEditorWidth(editorRoot) - 24;
            var newWidth = clamp(Math.round(editorWidth * ratio), 140, editorWidth);
            ensureImageClasses(state.activeImg);
            state.activeImg.style.width = newWidth + 'px';
            state.activeImg.removeAttribute('width');
            state.activeImg.removeAttribute('height');
            positionOverlay();
        }

        function uploadImage() {
            var input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/webp,image/gif';
            input.addEventListener('change', function () {
                var file = input.files && input.files[0] ? input.files[0] : null;
                if (!file) {
                    return;
                }

                var formData = new FormData();
                formData.append('image', file);
                if (options.csrfToken) {
                    formData.append('csrf_token', options.csrfToken);
                }

                fetch(options.uploadUrl, {
                    method: 'POST',
                    credentials: 'include',
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || !data.url) {
                            throw new Error(data && data.error ? data.error : 'Erreur upload image');
                        }
                        var prefix = options.uploadPrefix || '';
                        insertUploadedImage(prefix + data.url);
                    })
                    .catch(function (error) {
                        var message = error && error.message ? error.message : 'Erreur upload image';
                        if (window.emspUI && typeof window.emspUI.showError === 'function') {
                            window.emspUI.showError('Upload image', message);
                            return;
                        }
                        window.alert(message);
                    });
            });
            input.click();
        }

        editorRoot.querySelectorAll('img').forEach(function (img) {
            ensureImageClasses(img);
        });

        editorRoot.addEventListener('click', function (event) {
            var target = event.target;
            if (target && target.tagName === 'IMG') {
                selectImage(target);
                return;
            }
            if (!overlay.contains(target)) {
                clearSelection();
            }
        });

        document.addEventListener('click', function (event) {
            if (!state.activeImg) {
                return;
            }
            if (wrapper.contains(event.target) || overlay.contains(event.target)) {
                return;
            }
            clearSelection();
        });

        leftBtn.addEventListener('click', function () {
            if (!state.activeImg) {
                return;
            }
            applyAlignment(state.activeImg, 'left');
            positionOverlay();
        });

        centerBtn.addEventListener('click', function () {
            if (!state.activeImg) {
                return;
            }
            applyAlignment(state.activeImg, 'center');
            positionOverlay();
        });

        rightBtn.addEventListener('click', function () {
            if (!state.activeImg) {
                return;
            }
            applyAlignment(state.activeImg, 'right');
            positionOverlay();
        });

        upBtn.addEventListener('click', function () {
            moveImage(-1);
        });

        downBtn.addEventListener('click', function () {
            moveImage(1);
        });

        smallBtn.addEventListener('click', function () {
            applyPresetWidth(0.4);
        });

        mediumBtn.addEventListener('click', function () {
            applyPresetWidth(0.62);
        });

        largeBtn.addEventListener('click', function () {
            applyPresetWidth(0.92);
        });

        resizeHandle.addEventListener('mousedown', function (event) {
            if (!state.activeImg) {
                return;
            }
            event.preventDefault();
            state.resizing = true;
            state.startX = event.clientX;
            state.startWidth = state.activeImg.getBoundingClientRect().width;
        });

        document.addEventListener('mousemove', function (event) {
            if (!state.resizing || !state.activeImg) {
                return;
            }
            event.preventDefault();
            var delta = event.clientX - state.startX;
            var editorWidth = readEditorWidth(editorRoot) - 24;
            var newWidth = clamp(state.startWidth + delta, 120, editorWidth);
            ensureImageClasses(state.activeImg);
            state.activeImg.style.width = Math.round(newWidth) + 'px';
            state.activeImg.removeAttribute('width');
            state.activeImg.removeAttribute('height');
            positionOverlay();
        });

        document.addEventListener('mouseup', function () {
            state.resizing = false;
        });

        window.addEventListener('resize', positionOverlay);
        wrapper.addEventListener('scroll', positionOverlay, true);

        var toolbarModule = quill.getModule('toolbar');
        if (toolbarModule) {
            toolbarModule.addHandler('image', uploadImage);
        }

        return {
            selectImage: selectImage,
            clearSelection: clearSelection,
            positionOverlay: positionOverlay
        };
    };
})();


