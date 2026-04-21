
(function () {
    var modalElement = document.getElementById('journalModal');
    if (!modalElement || typeof bootstrap === 'undefined') {
        return;
    }

    var isAuthenticated = true;
    var modal = new bootstrap.Modal(modalElement);
    var csrfInput = document.getElementById('journal-modal-csrf');
    var csrfToken = csrfInput ? csrfInput.value : '';
    var titleEl = document.getElementById('jm-title');
    var dateEl = document.getElementById('jm-date');
    var stateEl = document.getElementById('jm-state');
    var contentEl = document.getElementById('jm-content');
    var interactionEl = document.getElementById('jm-interaction');
    var socialEl = document.getElementById('jm-social');
    var footerNoteEl = document.getElementById('jm-footer-note');
    var openLinkEl = document.getElementById('jm-open-link');
    var currentSummary = null;
    var latestJournalId = 1;
    var newBadge = document.getElementById('journal-new-badge');
    var lastSeenKey = 'emsp-journal-last-seen';

    function markLastSeen(id) {
        if (!id || !window.localStorage) {
            return;
        }
        try {
            window.localStorage.setItem(lastSeenKey, String(id));
        } catch (e) {}
    }

    function refreshBadge() {
        if (!newBadge || !latestJournalId || !window.localStorage) {
            return;
        }
        var lastSeen = 0;
        try {
            lastSeen = parseInt(window.localStorage.getItem(lastSeenKey) || '0', 10);
        } catch (e) {
            lastSeen = 0;
        }
        if (latestJournalId > lastSeen) {
            newBadge.classList.remove('d-none');
        } else {
            newBadge.classList.add('d-none');
        }
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderState(summary) {
        var html = '';
        if (summary.type_meta) {
            html += '<span class="journal-type-badge" style="color:' + esc(summary.type_meta.color || '#1a3c6e') + ';background:' + esc(summary.type_meta.color || '#1a3c6e') + '14;">' + esc(summary.type_meta.label || 'Article') + '</span>';
        }
        if (summary.state) {
            html += '<span class="journal-state-badge">' + esc(summary.state.label || 'Etat') + '</span>';
            if (summary.state.starts_at_label) {
                html += '<span class="small text-muted">Debut : ' + esc(summary.state.starts_at_label) + '</span>';
            }
            if (summary.state.ends_at_label) {
                html += '<span class="small text-muted">Fin : ' + esc(summary.state.ends_at_label) + '</span>';
            }
            if (summary.state.closed_at_label) {
                html += '<span class="small text-muted">Clos : ' + esc(summary.state.closed_at_label) + '</span>';
            }
        }
        stateEl.innerHTML = html;
    }

    function renderPoll(summary) {
        var poll = summary.poll || { options: [], total_votes: 0, my_option: 0 };
        if (!poll.options.length) {
            interactionEl.innerHTML = '<div class="alert alert-warning mb-0">Aucune option configuree pour ce sondage.</div>';
            return;
        }
        var total = parseInt(poll.total_votes || 0, 10);
        var controls = poll.options.map(function (option) {
            var votes = parseInt(option.votes || 0, 10);
            var percent = total > 0 ? Math.round((votes * 100) / total) : 0;
            var isMine = parseInt(poll.my_option || 0, 10) === parseInt(option.id || 0, 10);
            var disabled = (!summary.state || !summary.state.is_open || !isAuthenticated) ? 'disabled' : '';
            return '<div class="journal-poll-option' + (isMine ? ' active' : '') + ' mb-3" data-poll-card data-option-id="' + esc(option.id) + '">'
                + '<div class="d-flex justify-content-between align-items-center gap-3 mb-2">'
                + '<button type="button" class="btn btn-sm ' + (isMine ? 'btn-emsp' : 'btn-emsp-outline') + '" data-modal-poll-option data-option-id="' + esc(option.id) + '" ' + disabled + '>' + esc(option.label) + '</button>'
                + '<strong>' + votes + ' vote(s)</strong>'
                + '</div>'
                + '<div class="journal-poll-progress"><span style="width:' + percent + '%"></span></div>'
                + '<div class="small text-muted mt-2">' + percent + '% des votes</div>'
                + '</div>';
        }).join('');
        var pollNote = summary.state && !summary.state.is_open
            ? 'Resultat final du sondage.'
            : 'Les resultats restent visibles publiquement.';
        interactionEl.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-3"><strong>' + total + ' vote(s)</strong><span class="small text-muted">' + pollNote + '</span></div>' + controls;
    }

    function renderDefi(summary) {
        var defi = summary.defi || { participant_total: 0, participated: false, note: '' };
        var disabled = (!summary.state || !summary.state.is_open || !isAuthenticated) ? 'disabled' : '';
        var loginNotice = isAuthenticated ? '' : '<div class="alert alert-info">Connectez-vous pour participer a ce defi.</div>';
        interactionEl.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-3"><strong>' + parseInt(defi.participant_total || 0, 10) + ' participation(s)</strong><span class="small text-muted">Les notes restent visibles seulement cote admin.</span></div>'
            + loginNotice
            + (isAuthenticated ? '<div class="mb-3"><label class="form-label fw-semibold" for="jm-defi-note">Votre note</label><textarea class="form-control" id="jm-defi-note" rows="5" ' + disabled + '>' + esc(defi.note || '') + '</textarea></div><div class="d-flex flex-wrap gap-3 align-items-center"><button type="button" class="btn btn-emsp" id="jm-defi-submit" ' + disabled + '>' + (defi.participated ? 'Mettre a jour ma participation' : 'Je participe') + '</button><span class="small text-muted" id="jm-defi-feedback">' + (defi.participated ? 'Votre participation est deja enregistree.' : 'Cliquez pour participer.') + '</span></div>' : '');
    }

    function renderAnnouncement() {
        interactionEl.innerHTML = '';
    }

    function renderSocial(summary) {
        if (!socialEl) {
            return;
        }
        var likeCount = parseInt(summary.like_count || 0, 10);
        var commentCount = parseInt(summary.comment_count || 0, 10);
        var liked = !!summary.liked;
        var likeLabel = liked ? 'Aime' : 'J aime';
        var likeClass = liked ? 'btn-emsp' : 'btn-emsp-outline';
        var disabled = isAuthenticated ? '' : 'disabled';
        var comments = Array.isArray(summary.comments) ? summary.comments : [];
        var commentsHtml = comments.length
            ? comments.map(function (comment) {
                var avatar = comment.photo_src
                    ? '<img src="' + esc(comment.photo_src) + '" alt="" class="rounded-circle" style="width:42px;height:42px;object-fit:cover;">'
                    : '<span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold" style="width:42px;height:42px;background:#e7eef7;color:#1a3c6e;">' + esc(comment.initials || 'EM') + '</span>';
                return ''
                    + '<div class="d-flex gap-3 py-3 border-bottom">'
                    + '<div>' + avatar + '</div>'
                    + '<div class="flex-grow-1">'
                    + '<div class="small text-muted mb-1"><strong>' + esc(comment.display_name || 'Utilisateur') + '</strong> Â· ' + esc(comment.relative_date || '') + '</div>'
                    + '<div>' + esc(comment.content || '').replace(/\\n/g, '<br>') + '</div>'
                    + '</div>'
                    + '</div>';
            }).join('')
            : '<div class="small text-muted">Aucun commentaire pour le moment.</div>';
        socialEl.innerHTML = ''
            + '<div class="d-flex flex-wrap align-items-center gap-3">'
            + '<button type="button" class="btn btn-sm ' + likeClass + '" id="jm-like-btn" ' + disabled + '>'
            + '<i class="bi ' + (liked ? 'bi-heart-fill' : 'bi-heart') + ' me-1"></i>' + likeLabel
            + '</button>'
            + '<span class="small text-muted" id="jm-like-count">' + likeCount + ' like(s)</span>'
            + '<span class="small text-muted">' + commentCount + ' commentaire(s)</span>'
            + (isAuthenticated ? '' : '<span class="small text-muted">Connectez-vous pour aimer ou commenter.</span>')
            + '</div>';
        socialEl.innerHTML += '<div class="mt-3" id="jm-comments">' + commentsHtml + '</div>';
        if (isAuthenticated) {
            socialEl.innerHTML += ''
                + '<div class="mt-3">'
                + '<label class="form-label fw-semibold" for="jm-comment-input">Ajouter un commentaire</label>'
                + '<textarea class="form-control" id="jm-comment-input" rows="3" placeholder="Votre commentaire..."></textarea>'
                + '<div class="d-flex flex-wrap align-items-center gap-3 mt-2">'
                + '<button type="button" class="btn btn-emsp btn-sm" id="jm-comment-submit"><i class="bi bi-send-fill me-1"></i>Publier</button>'
                + '<span class="small text-danger" id="jm-comment-error" style="display:none;"></span>'
                + '</div>'
                + '</div>';
        }
    }

    function renderSummary(summary) {
        currentSummary = summary;
        titleEl.textContent = summary.title || 'Lecture rapide';
        dateEl.textContent = summary.relative_date || '';
        contentEl.innerHTML = summary.content_html || '';
        openLinkEl.href = 'news-article.php?id=' + encodeURIComponent(summary.id || '');
        footerNoteEl.textContent = summary.state && summary.state.is_open ? 'Contenu actuellement ouvert.' : 'Contenu non interactif pour le moment.';
        renderState(summary);
        if (summary.type === 'sondage') {
            renderPoll(summary);
        } else if (summary.type === 'defi') {
            renderDefi(summary);
        } else {
            renderAnnouncement();
        }
        renderSocial(summary);
        markLastSeen(summary.id);
        refreshBadge();
    }

    function loadSummary(id) {
        titleEl.textContent = 'Chargement...';
        dateEl.textContent = '';
        stateEl.innerHTML = '';
        contentEl.innerHTML = '<div class="text-muted">Chargement du contenu...</div>';
        interactionEl.innerHTML = '';
        footerNoteEl.textContent = '';
        openLinkEl.href = '#';
        modal.show();

        fetch('journal-action.php?action=summary&journal_id=' + encodeURIComponent(id), {
            credentials: 'include'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok || !payload.summary) {
                    throw new Error('summary');
                }
                renderSummary(payload.summary);
            })
            .catch(function () {
                contentEl.innerHTML = '<div class="alert alert-danger mb-0">Impossible de charger cette lecture rapide pour le moment.</div>';
            });
    }

    document.querySelectorAll('.btn-read-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            loadSummary(this.getAttribute('data-journal-id'));
        });
    });

    document.querySelectorAll('a[href^="news-article.php?id="]').forEach(function (link) {
        link.addEventListener('click', function () {
            var url = new URL(this.getAttribute('href'), window.location.href);
            var id = parseInt(url.searchParams.get('id') || '0', 10);
            if (id > 0) {
                markLastSeen(id);
                refreshBadge();
            }
        });
    });

    refreshBadge();

    interactionEl.addEventListener('click', function (event) {
        var pollButton = event.target.closest('[data-modal-poll-option]');
        if (pollButton && currentSummary) {
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'vote');
            formData.append('journal_id', currentSummary.id);
            formData.append('option_id', pollButton.getAttribute('data-option-id'));
            fetch('journal-action.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('vote');
                    }
                    renderSummary(payload.summary);
                })
                .catch(function (payload) {
                    if (payload && payload.summary) {
                        renderSummary(payload.summary);
                    }
                    var msg = payload && payload.message ? payload.message : 'Impossible d enregistrer votre vote pour le moment.';
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Vote indisponible', msg);
                    } else {
                        console.error(msg);
                    }
                });
            return;
        }

        var defiButton = event.target.closest('#jm-defi-submit');
        if (defiButton && currentSummary) {
            var note = document.getElementById('jm-defi-note');
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'defi');
            formData.append('journal_id', currentSummary.id);
            formData.append('note', note ? note.value : '');
            fetch('journal-action.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('defi');
                    }
                    renderSummary(payload.summary);
                })
                .catch(function (payload) {
                    if (payload && payload.summary) {
                        renderSummary(payload.summary);
                    }
                    var feedback = document.getElementById('jm-defi-feedback');
                    if (feedback) {
                        feedback.textContent = payload && payload.message
                            ? payload.message
                            : 'Impossible d enregistrer la participation pour le moment.';
                    }
                });
        }
    });

    if (socialEl) {
        socialEl.addEventListener('click', function (event) {
            var likeButton = event.target.closest('#jm-like-btn');
            if (!likeButton || !currentSummary) {
                return;
            }
            if (!csrfToken || !currentSummary.id) {
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'like');
            fd.append('journal_id', currentSummary.id);
            fetch('journal-action.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        throw payload || new Error('like');
                    }
                    if (payload.summary) {
                        renderSummary(payload.summary);
                        return;
                    }
                    var liked = !!payload.liked;
                    likeButton.classList.toggle('btn-emsp', liked);
                    likeButton.classList.toggle('btn-emsp-outline', !liked);
                    likeButton.innerHTML = '<i class="bi ' + (liked ? 'bi-heart-fill' : 'bi-heart') + ' me-1"></i>' + (liked ? 'Aime' : 'J aime');
                    var count = document.getElementById('jm-like-count');
                    if (count) { count.textContent = (payload.like_count || 0) + ' like(s)'; }
                })
                .catch(function (payload) {
                    var msg = payload && payload.message ? payload.message : 'Impossible d enregistrer le like.';
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Like indisponible', msg);
                    } else {
                        console.error(msg);
                    }
                });
        });

        socialEl.addEventListener('click', function (event) {
            var commentButton = event.target.closest('#jm-comment-submit');
            if (!commentButton || !currentSummary) {
                return;
            }
            var commentInput = document.getElementById('jm-comment-input');
            var commentError = document.getElementById('jm-comment-error');
            var content = commentInput ? String(commentInput.value || '').trim() : '';
            if (commentError) {
                commentError.textContent = '';
                commentError.style.display = 'none';
            }
            if (content === '') {
                if (commentError) {
                    commentError.textContent = 'Le commentaire est vide.';
                    commentError.style.display = 'inline';
                }
                return;
            }
            var commentFd = new FormData();
            commentFd.append('csrf_token', csrfToken);
            commentFd.append('action', 'comment');
            commentFd.append('journal_id', currentSummary.id);
            commentFd.append('content', content);
            fetch('journal-action.php', {
                method: 'POST',
                body: commentFd,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('comment');
                    }
                    if (commentInput) {
                        commentInput.value = '';
                    }
                    renderSummary(payload.summary);
                })
                .catch(function (payload) {
                    if (commentError) {
                        commentError.textContent = payload && payload.message ? payload.message : 'Impossible d enregistrer le commentaire.';
                        commentError.style.display = 'inline';
                    }
                });
        });
    }
})();


