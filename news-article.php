<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/journal_helpers.php';
include_once __DIR__ . '/includes/content-helpers.php';
include_once __DIR__ . '/includes/notif-helper.php';

if (!empty($_SESSION['auth_user']['id'])) {
    emsp_mark_notifications_seen_for_section($con, (int) $_SESSION['auth_user']['id'], 'journal');
    if (!empty($_GET['id'])) {
        emsp_mark_notifications_seen_for_journal($con, (int) $_SESSION['auth_user']['id'], (int) $_GET['id']);
    }
}

$articleId = (int) ($_GET['id'] ?? 0);
$userId = (int) ($_SESSION['auth_user']['id'] ?? 0);
$summary = $articleId > 0 ? emsp_journal_fetch_public_summary($con, $articleId, $userId) : null;
$journalLikes = (int) ($summary['like_count'] ?? 0);
$journalLiked = !empty($summary['liked']);
$journalComments = $summary['comments'] ?? [];
$journalCommentCount = (int) ($summary['comment_count'] ?? count($journalComments));
$journalAuthorId = (int) ($summary['owner_id'] ?? 0);
$page_title = $summary ? (string) ($summary['title'] ?? 'Journal EMSP') : 'Article introuvable';
$articleMeta = $summary['type_meta'] ?? ['label' => 'Article', 'color' => '#004D2A'];
$articleTypeKey = strtolower(trim((string) ($summary['type'] ?? 'annonce')));
if (!in_array($articleTypeKey, ['annonce', 'defi', 'sondage'], true)) {
    $articleTypeKey = 'annonce';
}
$state = $summary['state'] ?? ['label' => 'Brouillon', 'code' => 'draft'];
$csrfToken = generate_csrf_token();
$coverImage = $summary ? emsp_journal_cover_src((string) ($summary['content_html'] ?? '')) : '';
$extraHead = [
    '<meta property="og:title" content="' . htmlspecialchars($page_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
    '<meta property="og:description" content="' . htmlspecialchars(emsp_journal_excerpt((string) ($summary['content_html'] ?? ''), 160), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
    '<meta property="og:url" content="' . htmlspecialchars('https://emspdocs.unaux.com/news-article.php?id=' . $articleId, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
    '<meta property="og:type" content="article">',
    '<meta property="og:site_name" content="EMSP Docs">',
];
if ($coverImage !== '') {
    $extraHead[] = '<meta property="og:image" content="' . htmlspecialchars($coverImage, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
}
$extra_head_tags = implode("\n", $extraHead);
include __DIR__ . '/includes/header.php';
?>
<style>
.journal-rich-content h1,
.journal-rich-content h2,
.journal-rich-content h3 { margin-top: 1rem; }
.journal-rich-content a { color: #1a5fb4; }
.journal-rich-content blockquote {
    border-left: 4px solid #006B3C;
    padding-left: 1rem;
    color: #64748b;
}
.journal-hero-card,
.journal-interaction-card,
.journal-state-card {
    border-radius: 24px;
    border: 1px solid rgba(26, 60, 110, 0.1);
    box-shadow: 0 18px 34px rgba(26, 60, 110, 0.06);
}
.journal-meta-pill {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .45rem .9rem;
    border-radius: 999px;
    font-weight: 800;
}
.journal-poll-option {
    border: 1px solid rgba(26, 60, 110, 0.12);
    border-radius: 18px;
    padding: 1rem;
    background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
}
.journal-poll-option.active {
    border-color: rgba(8, 97, 54, 0.36);
    box-shadow: 0 12px 24px rgba(8, 97, 54, 0.08);
}
.journal-poll-progress {
    width: 100%;
    height: .6rem;
    border-radius: 999px;
    background: #e7edf5;
    overflow: hidden;
}
.journal-poll-progress span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #006B3C, #27AE60);
}
.journal-defi-note {
    min-height: 120px;
    resize: vertical;
}
.journal-social-card {
    border-radius: 24px;
    border: 1px solid rgba(26, 60, 110, 0.1);
    box-shadow: 0 18px 34px rgba(26, 60, 110, 0.06);
}
.journal-social-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: center;
}
.journal-comment-item {
    display: flex;
    gap: .85rem;
    padding: .85rem 0;
    border-bottom: 1px solid rgba(26, 60, 110, .08);
}
.journal-comment-item:last-child { border-bottom: 0; }
.journal-comment-avatar {
    width: 44px; height: 44px; border-radius: 50%;
    background: #e8edf7;
    display:flex; align-items:center; justify-content:center;
    font-weight:800; color:#004D2A; flex-shrink:0;
}
.journal-comment-avatar img { width:100%; height:100%; border-radius:50%; object-fit:cover; }
.journal-comment-content { font-size:.9rem; color:#1f2a44; }
.journal-comment-meta { font-size:.75rem; color:#7c8aa5; }
</style>
<section class="page-header">
    <div class="container">
        <h1><?= htmlspecialchars($page_title, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h1>
        <?php if ($summary): ?>
            <p class="mb-0 text-muted"><?= htmlspecialchars((string) ($summary['relative_date'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?> · <?= htmlspecialchars((string) ($articleMeta['label'] ?? 'Article'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        <?php if (!$summary): ?>
            <div class="alert alert-warning">Article introuvable.</div>
            <a class="btn btn-emsp" href="news-blog.php">Retour au journal</a>
        <?php else: ?>
            <div class="mb-3">
                <a class="btn btn-emsp-outline btn-sm" href="news-blog.php">? Retour au journal</a>
            </div>

            <div class="card journal-state-card shadow-sm border-0 mb-4">
                <div class="card-body p-4 d-flex flex-wrap justify-content-between gap-3 align-items-start">
                    <div>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="journal-meta-pill journal-type-badge journal-type-<?= h($articleTypeKey) ?>">
                                <?= htmlspecialchars((string) ($articleMeta['label'] ?? 'Article'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                            </span>
                            <span class="badge text-bg-light border" id="journal-state-label"><?= htmlspecialchars((string) ($state['label'] ?? 'Etat'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                        </div>
                        <div class="small text-muted" id="journal-state-details">
                            <?php if (!empty($state['starts_at_label'])): ?><div>Ouvre le <?= htmlspecialchars((string) $state['starts_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div><?php endif; ?>
                            <?php if (!empty($state['ends_at_label'])): ?><div>Se termine le <?= htmlspecialchars((string) $state['ends_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div><?php endif; ?>
                            <?php if (!empty($state['closed_at_label'])): ?><div>Clos le <?= htmlspecialchars((string) $state['closed_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="small text-muted text-lg-end">
                        <div>Publie <?= htmlspecialchars((string) ($summary['relative_date'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                        <?php if (($summary['type'] ?? '') === 'sondage'): ?>
                            <div id="journal-total-label"><?= (int) ($summary['poll']['total_votes'] ?? 0) ?> vote(s)</div>
                        <?php elseif (($summary['type'] ?? '') === 'defi'): ?>
                            <div id="journal-total-label"><?= (int) ($summary['defi']['participant_total'] ?? 0) ?> participation(s)</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <article class="card journal-hero-card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="h3 fw-bold mb-3"><?= htmlspecialchars((string) ($summary['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h2>
                    <div class="journal-rich-content">
                        <?= (string) ($summary['content_html'] ?? '') ?>
                    </div>
                </div>
            </article>

            <?php if (($summary['type'] ?? '') === 'sondage'): ?>
                <?php $poll = $summary['poll'] ?? ['options' => [], 'total_votes' => 0, 'my_option' => 0]; ?>
                <section class="card journal-interaction-card shadow-sm border-0 mt-4">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                            <div>
                                <span class="journal-meta-pill journal-meta-pill-poll">
                                    <i class="bi bi-bar-chart-fill"></i>Sondage EMSP
                                </span>
                                <h3 class="h4 fw-bold mt-3 mb-2">
                                    <?= !empty($state['is_open']) ? 'Resultats visibles en temps reel' : 'Resultat final du sondage' ?>
                                </h3>
                                <p class="text-muted mb-0">
                                    <?= !empty($state['is_open'])
                                        ? 'Chaque utilisateur peut modifier son vote tant que le sondage reste ouvert.'
                                        : 'Le sondage est cloture et les votes sont figees.' ?>
                                </p>
                            </div>
                            <span class="badge text-bg-light border" id="poll-summary-label"><?= (int) ($poll['total_votes'] ?? 0) ?> vote(s)</span>
                        </div>

                        <?php if (empty($_SESSION['auth_user']['id'])): ?>
                            <div class="alert alert-info">Connectez-vous pour voter. Les resultats restent visibles publiquement.</div>
                        <?php endif; ?>

                        <div class="d-grid gap-3" id="poll-options">
                            <?php foreach (($poll['options'] ?? []) as $option): ?>
                                <?php
                                $votes = (int) ($option['votes'] ?? 0);
                                $totalVotes = (int) ($poll['total_votes'] ?? 0);
                                $percent = $totalVotes > 0 ? (int) round(($votes * 100) / max(1, $totalVotes)) : 0;
                                $isActive = (int) ($poll['my_option'] ?? 0) === (int) ($option['id'] ?? 0);
                                $isOpen = !empty($state['is_open']);
                                ?>
                                <div class="journal-poll-option<?= $isActive ? ' active' : '' ?>" data-poll-card data-option-id="<?= (int) ($option['id'] ?? 0) ?>">
                                    <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                        <button type="button"
                                                class="btn btn-sm <?= $isActive ? 'btn-emsp' : 'btn-emsp-outline' ?>"
                                                data-poll-option
                                                data-option-id="<?= (int) ($option['id'] ?? 0) ?>"
                                                <?= $isOpen && !empty($_SESSION['auth_user']['id']) ? '' : 'disabled' ?>>
                                            <?= htmlspecialchars((string) ($option['label'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                        </button>
                                        <strong data-poll-votes><?= $votes ?> vote(s)</strong>
                                    </div>
                                    <div class="journal-poll-progress"><span data-poll-bar class="journal-poll-progress-fill" data-emsp-width="<?= (int) $percent ?>"></span></div>
                                    <div class="small text-muted mt-2"><span data-poll-percent><?= $percent ?></span>% des votes</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php elseif (($summary['type'] ?? '') === 'defi'): ?>
                <?php $defi = $summary['defi'] ?? ['participant_total' => 0, 'participated' => false, 'note' => '']; ?>
                <section class="card journal-interaction-card shadow-sm border-0 mt-4">
                    <div class="card-body p-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                            <div>
                                <span class="journal-meta-pill journal-meta-pill-defi">
                                    <i class="bi bi-lightning-charge-fill"></i>Defi EMSP
                                </span>
                                <h3 class="h4 fw-bold mt-3 mb-2">Participation au defi</h3>
                                <p class="text-muted mb-0">Le nombre de participants reste public. Les notes restent reservees a l admin.</p>
                            </div>
                            <span class="badge text-bg-light border" id="defi-summary-label"><?= (int) ($defi['participant_total'] ?? 0) ?> participation(s)</span>
                        </div>

                        <?php if (empty($_SESSION['auth_user']['id'])): ?>
                            <div class="alert alert-info">Connectez-vous pour participer a ce defi.</div>
                        <?php else: ?>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold" for="defi-note">Votre note</label>
                                    <textarea class="form-control journal-defi-note" id="defi-note" <?= !empty($state['is_open']) ? '' : 'disabled' ?>><?= htmlspecialchars((string) ($defi['note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></textarea>
                                </div>
                                <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                                    <button type="button" class="btn btn-emsp" id="defi-submit-btn" <?= !empty($state['is_open']) ? '' : 'disabled' ?>>
                                        <i class="bi bi-lightning-charge-fill me-2"></i><?= !empty($defi['participated']) ? 'Mettre a jour ma participation' : 'Je participe' ?>
                                    </button>
                                    <span class="text-muted small" id="defi-feedback"><?= !empty($defi['participated']) ? 'Votre participation est deja enregistree.' : 'Cliquez pour rejoindre le defi.' ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <input type="hidden" id="journal-csrf-token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
            <input type="hidden" id="journal-id" value="<?= (int) $articleId ?>">

            <section class="card journal-social-card shadow-sm border-0 mt-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                        <div class="journal-social-actions">
                            <button type="button" class="btn btn-sm <?= $journalLiked ? 'btn-emsp' : 'btn-emsp-outline' ?>" id="journal-like-btn">
                                <i class="bi <?= $journalLiked ? 'bi-heart-fill' : 'bi-heart' ?> me-1"></i>
                                <span id="journal-like-label"><?= $journalLiked ? 'Aime' : 'J aime' ?></span>
                            </button>
                            <span class="small text-muted" id="journal-like-count"><?= (int) $journalLikes ?> like(s)</span>
                            <span class="small text-muted" id="journal-comment-count"><?= (int) $journalCommentCount ?> commentaire(s)</span>
                        </div>
                        <?php if (empty($_SESSION['auth_user']['id'])): ?>
                            <span class="text-muted small">Connectez-vous pour aimer ou commenter.</span>
                        <?php endif; ?>
                    </div>

                    <div id="journal-comments">
                        <?php if ($journalCommentCount === 0): ?>
                            <div class="text-muted small">Aucun commentaire pour le moment.</div>
                        <?php else: ?>
                            <?php foreach ($journalComments as $jc): ?>
                                <div class="journal-comment-item">
                                    <div class="journal-comment-avatar">
                                        <?php if (!empty($jc['photo_src'])): ?>
                                            <img src="<?= h((string) $jc['photo_src']) ?>" alt="">
                                        <?php else: ?>
                                            <?= h((string) ($jc['initials'] ?? 'EM')) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="journal-comment-meta">
                                            <strong><?= h((string) ($jc['display_name'] ?? 'Utilisateur')) ?></strong>
                                            · <?= h((string) ($jc['relative_date'] ?? '')) ?>
                                        </div>
                                        <div class="journal-comment-content"><?= nl2br(h((string) ($jc['content'] ?? ''))) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($_SESSION['auth_user']['id'])): ?>
                        <div class="mt-3">
                            <label class="form-label fw-semibold" for="journal-comment-input">Ajouter un commentaire</label>
                            <textarea class="form-control" id="journal-comment-input" rows="3" placeholder="Votre commentaire..."></textarea>
                            <button type="button" class="btn btn-emsp mt-2" id="journal-comment-submit">
                                <i class="bi bi-send-fill me-1"></i>Publier
                            </button>
                            <div class="text-danger small mt-2 emsp-hidden" id="journal-comment-error"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</section>
<?php
$pageSummaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$page_scripts = <<<HTML
<script>
(function () {
    var summary = {$pageSummaryJson};
    if (!summary) {
        return;
    }

    try {
        if (summary.id && window.localStorage) {
            window.localStorage.setItem('emsp-journal-last-seen', String(summary.id));
        }
    } catch (e) {}

    var csrfToken = document.getElementById('journal-csrf-token');
    var journalIdInput = document.getElementById('journal-id');
    var csrf = csrfToken ? csrfToken.value : '';
    var journalId = journalIdInput ? journalIdInput.value : '';

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderState(nextSummary) {
        var state = nextSummary && nextSummary.state ? nextSummary.state : {};
        var label = document.getElementById('journal-state-label');
        var details = document.getElementById('journal-state-details');
        if (label) {
            label.textContent = state.label || 'Etat';
        }
        if (details) {
            var html = '';
            if (state.starts_at_label) {
                html += '<div>Ouvre le ' + esc(state.starts_at_label) + '</div>';
            }
            if (state.ends_at_label) {
                html += '<div>Se termine le ' + esc(state.ends_at_label) + '</div>';
            }
            if (state.closed_at_label) {
                html += '<div>Clos le ' + esc(state.closed_at_label) + '</div>';
            }
            details.innerHTML = html;
        }
    }

    function renderPoll(nextSummary) {
        var poll = nextSummary && nextSummary.poll ? nextSummary.poll : null;
        if (!poll || !Array.isArray(poll.options)) {
            return;
        }
        var total = parseInt(poll.total_votes || 0, 10);
        var summaryLabel = document.getElementById('poll-summary-label');
        var totalLabel = document.getElementById('journal-total-label');
        if (summaryLabel) {
            summaryLabel.textContent = total + ' vote(s)';
        }
        if (totalLabel) {
            totalLabel.textContent = total + ' vote(s)';
        }

        poll.options.forEach(function (option) {
            var votes = parseInt(option.votes || 0, 10);
            var percent = total > 0 ? Math.round((votes * 100) / total) : 0;
            var card = document.querySelector('[data-poll-card][data-option-id="' + option.id + '"]');
            if (!card) {
                return;
            }
            var votesEl = card.querySelector('[data-poll-votes]');
            var percentEl = card.querySelector('[data-poll-percent]');
            var barEl = card.querySelector('[data-poll-bar]');
            var button = card.querySelector('[data-poll-option]');
            var isMine = parseInt(poll.my_option || 0, 10) === parseInt(option.id, 10);
            if (votesEl) {
                votesEl.textContent = votes + ' vote(s)';
            }
            if (percentEl) {
                percentEl.textContent = percent;
            }
            if (barEl) {
                barEl.style.width = percent + '%';
            }
            card.classList.toggle('active', isMine);
            if (button) {
                button.classList.toggle('btn-emsp', isMine);
                button.classList.toggle('btn-emsp-outline', !isMine);
                button.disabled = !(nextSummary.state && nextSummary.state.is_open) || !csrf;
            }
        });
    }

    function renderDefi(nextSummary) {
        var defi = nextSummary && nextSummary.defi ? nextSummary.defi : null;
        if (!defi) {
            return;
        }
        var summaryLabel = document.getElementById('defi-summary-label');
        var totalLabel = document.getElementById('journal-total-label');
        var feedback = document.getElementById('defi-feedback');
        var button = document.getElementById('defi-submit-btn');
        var note = document.getElementById('defi-note');
        var total = parseInt(defi.participant_total || 0, 10);
        if (summaryLabel) {
            summaryLabel.textContent = total + ' participation(s)';
        }
        if (totalLabel) {
            totalLabel.textContent = total + ' participation(s)';
        }
        if (feedback) {
            feedback.textContent = defi.participated ? 'Votre participation est deja enregistree.' : 'Cliquez pour rejoindre le defi.';
        }
        if (button) {
            button.innerHTML = '<i class="bi bi-lightning-charge-fill me-2"></i>' + (defi.participated ? 'Mettre a jour ma participation' : 'Je participe');
            button.disabled = !(nextSummary.state && nextSummary.state.is_open);
        }
        if (note) {
            note.disabled = !(nextSummary.state && nextSummary.state.is_open);
            if (typeof defi.note === 'string') {
                note.value = defi.note;
            }
        }
    }

    function renderSocial(nextSummary) {
        var liked = !!(nextSummary && nextSummary.liked);
        var likeCount = parseInt((nextSummary && nextSummary.like_count) || 0, 10);
        var commentCount = parseInt((nextSummary && nextSummary.comment_count) || 0, 10);
        var likeLabel = document.getElementById('journal-like-label');
        var likeCountLabel = document.getElementById('journal-like-count');
        var likeButton = document.getElementById('journal-like-btn');
        if (likeLabel) {
            likeLabel.textContent = liked ? 'Aime' : 'J aime';
        }
        if (likeCountLabel) {
            likeCountLabel.textContent = likeCount + ' like(s)';
        }
        if (likeButton) {
            likeButton.classList.toggle('btn-emsp', liked);
            likeButton.classList.toggle('btn-emsp-outline', !liked);
            var icon = likeButton.querySelector('i');
            if (icon) {
                icon.className = liked ? 'bi bi-heart-fill me-1' : 'bi bi-heart me-1';
            }
        }

        var commentCountLabel = document.getElementById('journal-comment-count');
        if (commentCountLabel) {
            commentCountLabel.textContent = commentCount + ' commentaire(s)';
        }

        var commentsWrap = document.getElementById('journal-comments');
        if (!commentsWrap) {
            return;
        }
        var comments = Array.isArray(nextSummary && nextSummary.comments) ? nextSummary.comments : [];
        if (!comments.length) {
            commentsWrap.innerHTML = '<div class="text-muted small">Aucun commentaire pour le moment.</div>';
            return;
        }
        commentsWrap.innerHTML = comments.map(function (comment) {
            var avatar = comment.photo_src
                ? '<img src="' + esc(comment.photo_src) + '" alt="">'
                : esc(comment.initials || 'EM');
            var avatarClass = comment.photo_src ? 'journal-comment-avatar' : 'journal-comment-avatar';
            return ''
                + '<div class="journal-comment-item">'
                + '<div class="' + avatarClass + '">' + avatar + '</div>'
                + '<div>'
                + '<div class="journal-comment-meta"><strong>' + esc(comment.display_name || 'Utilisateur') + '</strong> · ' + esc(comment.relative_date || '') + '</div>'
                + '<div class="journal-comment-content">' + esc(comment.content || '').replace(/\\n/g, '<br>') + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function applySummary(nextSummary) {
        if (!nextSummary) {
            return;
        }
        summary = nextSummary;
        renderState(summary);
        renderPoll(summary);
        renderDefi(summary);
        renderSocial(summary);
    }

    document.querySelectorAll('[data-poll-option]').forEach(function (button) {
        button.addEventListener('click', function () {
            var optionId = this.getAttribute('data-option-id');
            var formData = new FormData();
            formData.append('csrf_token', csrf);
            formData.append('action', 'vote');
            formData.append('journal_id', journalId);
            formData.append('option_id', optionId);

            fetch('journal-action.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('poll');
                    }
                    applySummary(payload.summary);
                })
                .catch(function (payload) {
                    if (payload && payload.summary) {
                        applySummary(payload.summary);
                    }
                    var msg = payload && payload.message ? payload.message : 'Impossible d enregistrer votre vote pour le moment.';
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Vote indisponible', msg);
                    } else {
                        console.error(msg);
                    }
                });
        });
    });

    var defiButton = document.getElementById('defi-submit-btn');
    if (defiButton) {
        defiButton.addEventListener('click', function () {
            var note = document.getElementById('defi-note');
            var formData = new FormData();
            formData.append('csrf_token', csrf);
            formData.append('action', 'defi');
            formData.append('journal_id', journalId);
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
                    applySummary(payload.summary);
                })
                .catch(function (payload) {
                    if (payload && payload.summary) {
                        applySummary(payload.summary);
                    }
                    var feedback = document.getElementById('defi-feedback');
                    if (feedback) {
                        feedback.textContent = payload && payload.message
                            ? payload.message
                            : 'Impossible d enregistrer la participation pour le moment.';
                    }
                });
        });
    }

    var likeBtn = document.getElementById('journal-like-btn');
    if (likeBtn) {
        likeBtn.addEventListener('click', function () {
            if (!csrf || !journalId) {
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'like');
            fd.append('journal_id', journalId);

            fetch('journal-action.php', { method: 'POST', body: fd, credentials: 'include' })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        throw payload || new Error('like');
                    }
                    if (payload.summary) {
                        applySummary(payload.summary);
                        return;
                    }
                    renderSocial({
                        liked: !!payload.liked,
                        like_count: payload.like_count || 0,
                        comment_count: summary && summary.comment_count ? summary.comment_count : 0,
                        comments: summary && summary.comments ? summary.comments : []
                    });
                })
                .catch(function (payload) {
                    var likeMessage = payload && payload.message ? payload.message : 'Impossible d enregistrer votre like.';
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Like indisponible', likeMessage);
                    } else {
                        console.error(likeMessage);
                    }
                });
        });
    }

    var commentBtn = document.getElementById('journal-comment-submit');
    if (commentBtn) {
        commentBtn.addEventListener('click', function () {
            var input = document.getElementById('journal-comment-input');
            var error = document.getElementById('journal-comment-error');
            if (error) { error.textContent = ''; error.style.display = 'none'; }
            if (!input) { return; }
            var content = (input.value || '').trim();
            if (content === '') {
                if (error) { error.textContent = 'Le commentaire est vide.'; error.style.display = 'block'; }
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', csrf);
            fd.append('action', 'comment');
            fd.append('journal_id', journalId);
            fd.append('content', content);

            fetch('journal-action.php', { method: 'POST', body: fd, credentials: 'include' })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('comment');
                    }
                    input.value = '';
                    applySummary(payload.summary);
                })
                .catch(function (payload) {
                    if (error) {
                        error.textContent = payload && payload.message ? payload.message : 'Impossible d enregistrer le commentaire.';
                        error.style.display = 'block';
                    }
                });
        });
    }
})();
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>




