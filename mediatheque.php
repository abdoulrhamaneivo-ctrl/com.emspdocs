<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/content-helpers.php';
include_once __DIR__ . '/includes/notif-helper.php';

if (!empty($_SESSION['auth_user']['id'])) {
    emsp_mark_notifications_seen_for_section($con, (int) $_SESSION['auth_user']['id'], 'media');
}

function emsp_is_youtube(string $src): bool
{
    return stripos($src, 'youtube.com') !== false || stripos($src, 'youtu.be') !== false;
}

function emsp_youtube_embed(string $url): string
{
    if (!preg_match('#(?:v=|youtu\.be/|embed/)([a-zA-Z0-9_-]{11})#', $url, $m)) return '';
    return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0&autoplay=0';
}

function emsp_youtube_thumb(string $url): string
{
    if (!preg_match('#(?:v=|youtu\.be/|embed/)([a-zA-Z0-9_-]{11})#', $url, $m)) return 'assets/images/video-placeholder.jpg';
    return 'https://img.youtube.com/vi/' . $m[1] . '/mqdefault.jpg';
}

function emsp_video_initial(string $title): string
{
    $title = trim(strip_tags($title));
    if ($title === '') {
        return 'V';
    }

    return mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8');
}

function emsp_video_cache_key(array $video): string
{
    $id = (int) ($video['id'] ?? 0);
    $createdAt = trim((string) ($video['created_at'] ?? ''));
    if ($id > 0 && $createdAt !== '') {
        return 'video_' . $id . '_' . strtotime($createdAt);
    }
    if ($id > 0) {
        return 'video_' . $id;
    }
    return 'video_' . md5((string) ($video['file_path'] ?? ''));
}

function emsp_local_asset_exists(string $src): bool
{
    $src = trim($src);
    if ($src === '' || emsp_is_external_url($src) || str_starts_with($src, 'data:') || str_starts_with($src, 'blob:')) {
        return $src !== '';
    }

    $path = strtok($src, '?') ?: $src;
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

    return is_file($fullPath);
}

function emsp_existing_media_src(?string $path, string $fallback = 'assets/images/video-placeholder.jpg'): string
{
    $src = emsp_media_src((string) $path);
    if ($src === '' || !emsp_local_asset_exists($src)) {
        return $fallback;
    }

    return $src;
}

function emsp_video_poster_src(array $video): string
{
    $poster = trim((string) ($video['poster'] ?? ''));
    if ($poster !== '' && emsp_local_asset_exists($poster)) {
        return $poster;
    }

    return 'assets/images/video-placeholder.jpg';
}

function emsp_video_card_thumb(array $video): string
{
    if (!empty($video['is_youtube'])) {
        return '<img src="' . htmlspecialchars((string) ($video['thumb'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars((string) ($video['title'] ?? 'Video EMSP'), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
            . ' class="video-thumb-img"'
            . ' data-fallback-src="assets/images/video-placeholder.jpg"'
            . ' loading="lazy">';
    }

    $title = htmlspecialchars((string) ($video['title'] ?? 'Video EMSP'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $initiale = htmlspecialchars((string) ($video['initial'] ?? emsp_video_initial((string) ($video['title'] ?? 'Video EMSP'))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $src = htmlspecialchars((string) ($video['embed'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cacheKey = htmlspecialchars((string) ($video['cache_key'] ?? emsp_video_cache_key($video)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $poster = htmlspecialchars(emsp_video_poster_src($video), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '
    <div class="video-thumb-local" data-local-thumb data-video-src="' . $src . '" data-video-title="' . $title . '" data-video-initial="' . $initiale . '" data-video-cache-key="' . $cacheKey . '" data-video-poster="' . $poster . '">
        <img class="video-thumb-poster" alt="' . $title . '" loading="lazy" src="' . $poster . '">
        <div class="video-thumb-bg">
            <span class="video-initiale">' . $initiale . '</span>
            <div class="video-play-btn">
                <i class="bi bi-play-circle-fill"></i>
            </div>
        </div>
        <div class="video-thumb-title">' . $title . '</div>
    </div>';
}

function emsp_video_player_placeholder(array $video): string
{
    $title = htmlspecialchars((string) ($video['title'] ?? 'Video EMSP'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $initiale = htmlspecialchars((string) ($video['initial'] ?? emsp_video_initial((string) ($video['title'] ?? 'Video EMSP'))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $src = htmlspecialchars((string) ($video['embed'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $cacheKey = htmlspecialchars((string) ($video['cache_key'] ?? emsp_video_cache_key($video)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $poster = htmlspecialchars(emsp_video_poster_src($video), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '
    <button type="button" class="video-player-local-card has-poster" data-local-player-trigger data-video-src="' . $src . '" data-video-title="' . $title . '" data-video-initial="' . $initiale . '" data-video-cache-key="' . $cacheKey . '" data-video-poster="' . $poster . '">
        <img class="video-player-poster" alt="' . $title . '" src="' . $poster . '">
        <span class="video-player-type">Video EMSP</span>
        <span class="video-player-initial">' . $initiale . '</span>
        <div class="video-player-play"><i class="bi bi-play-circle-fill"></i></div>
        <div class="video-player-title">' . $title . '</div>
    </button>';
}

function emsp_date_fr(string $dateStr): string
{
    if (!$dateStr) return '';
    $ts = strtotime($dateStr);
    if ($ts === false) return '';
    $mois = ['janvier','fevrier','mars','avril','mai','juin','juillet','aout','septembre','octobre','novembre','decembre'];
    return date('j', $ts) . ' ' . $mois[(int)date('n', $ts)-1] . ' ' . date('Y', $ts);
}

function emsp_mediatheque_fix_text(string $text): string
{
    return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($text) : $text;
}

$album_view = trim($_GET['album'] ?? '');
$album_view_label = emsp_mediatheque_fix_text($album_view);
$page_album = 1;
$per_page_albums = 0;
$video_page = 1;
$per_page_videos = 0;

// Comptage albums
$total_albums = 0;
$cnt = mysqli_query($con,
    "SELECT COUNT(*) AS total FROM (
         SELECT COALESCE(TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')),'') AS cat
         FROM media
         WHERE type='image' AND is_public=1 AND status='published'
         GROUP BY COALESCE(TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')),'')
     ) t");
if ($cnt && $row = mysqli_fetch_assoc($cnt)) {
    $total_albums = (int)$row['total'];
}

$albums = [];
$sAlbums = mysqli_prepare(
    $con,
    "SELECT
        COALESCE(TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')),'') AS category,
        COUNT(*) AS nb_photos,
        MAX(created_at) AS last_date,
        MIN(id) AS cover_id,
        (SELECT file_path FROM media m2
         WHERE COALESCE(TRIM(REPLACE(REPLACE(m2.category, CHAR(160), ' '), '\t', ' ')),'')
               = COALESCE(TRIM(REPLACE(REPLACE(m.category, CHAR(160), ' '), '\t', ' ')),'')
           AND m2.type = 'image'
           AND m2.is_public = 1
           AND m2.status = 'published'
         ORDER BY m2.created_at DESC LIMIT 1) AS cover_path
     FROM media m
     WHERE m.type = 'image'
       AND m.is_public = 1
       AND m.status = 'published'
     GROUP BY COALESCE(TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')),'')
     ORDER BY last_date DESC"
);
mysqli_stmt_execute($sAlbums);
$rAlbums = emsp_stmt_fetch_all($sAlbums);
foreach ($rAlbums as $row) {
    $row['category_raw'] = (string)($row['category'] ?? '');
    $row['category'] = emsp_mediatheque_fix_text($row['category_raw']);
    $row['cover_src'] = $row['cover_path'] ? emsp_existing_media_src((string)$row['cover_path']) : 'assets/images/video-placeholder.jpg';
    $row['category_label'] = $row['category'] !== '' ? $row['category'] : 'Sans titre';
    $albums[] = $row;
}
mysqli_stmt_close($sAlbums);

// Si vue detail d'un album
$album_photos = [];
$album_total = 0;
$album_page = 1;
$per_page_photos = 0;
if ($album_view !== '') {
    $sCount = mysqli_prepare(
        $con,
        "SELECT COUNT(*) FROM media
         WHERE ( (TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')) = TRIM(REPLACE(REPLACE(?, CHAR(160), ' '), '\t', ' ')))
                 OR (category IS NULL AND TRIM(REPLACE(REPLACE(?, CHAR(160), ' '), '\t', ' ')) = '') )
           AND type='image' AND is_public=1 AND status='published'"
    );
    mysqli_stmt_bind_param($sCount, 'ss', $album_view, $album_view);
    mysqli_stmt_execute($sCount);
    mysqli_stmt_bind_result($sCount, $album_total);
    mysqli_stmt_fetch($sCount);
    mysqli_stmt_close($sCount);

    $sPhotos = mysqli_prepare(
        $con,
        "SELECT id, title, description, file_path, created_at
         FROM media
         WHERE ( (TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')) = TRIM(REPLACE(REPLACE(?, CHAR(160), ' '), '\t', ' ')))
                 OR (category IS NULL AND TRIM(REPLACE(REPLACE(?, CHAR(160), ' '), '\t', ' ')) = '') )
           AND type='image'
           AND is_public=1
           AND status='published'
         ORDER BY created_at DESC"
    );
    mysqli_stmt_bind_param($sPhotos, 'ss', $album_view, $album_view);
    mysqli_stmt_execute($sPhotos);
    $rPhotos = emsp_stmt_fetch_all($sPhotos);
    foreach ($rPhotos as $row) {
        $row['title'] = emsp_mediatheque_fix_text((string)($row['title'] ?? ''));
        $row['src'] = emsp_existing_media_src((string)$row['file_path']);
        $album_photos[] = $row;
    }
    mysqli_stmt_close($sPhotos);
}

// Videos
$videos = [];
$total_videos = 0;
$sVidCount = mysqli_prepare(
    $con,
    "SELECT COUNT(*) FROM media
     WHERE type IN ('video','lien')
       AND is_public = 1
       AND status = 'published'"
);
if ($sVidCount) {
    mysqli_stmt_execute($sVidCount);
    mysqli_stmt_bind_result($sVidCount, $total_videos);
    mysqli_stmt_fetch($sVidCount);
    mysqli_stmt_close($sVidCount);
}
$sVid = mysqli_prepare(
    $con,
    "SELECT id, title, description, file_path, poster_path, type, category, created_at
     FROM media
     WHERE type IN ('video','lien')
       AND is_public = 1
       AND status = 'published'
     ORDER BY created_at DESC"
);
if ($sVid) {
    mysqli_stmt_execute($sVid);
    $rVid = emsp_stmt_fetch_all($sVid);
    foreach ($rVid as $row) {
        $row['title'] = emsp_mediatheque_fix_text((string)($row['title'] ?? ''));
        $row['description'] = emsp_mediatheque_fix_text((string)($row['description'] ?? ''));
        $row['category'] = emsp_mediatheque_fix_text((string)($row['category'] ?? ''));
        $src = emsp_media_src((string)$row['file_path']);
        $row['is_youtube'] = emsp_is_youtube($src);
        $row['embed'] = $row['is_youtube'] ? emsp_youtube_embed($src) : $src;
        $row['thumb'] = $row['is_youtube'] ? emsp_youtube_thumb($src) : '';
        $row['poster'] = '';
        if (!$row['is_youtube'] && !empty($row['poster_path'])) {
            $posterSrc = emsp_media_src((string) $row['poster_path']);
            $row['poster'] = emsp_local_asset_exists($posterSrc) ? $posterSrc : '';
        }
        $row['initial'] = emsp_video_initial((string) $row['title']);
        $row['cache_key'] = emsp_video_cache_key($row);
        $videos[] = $row;
    }
    mysqli_stmt_close($sVid);
}

$page_title = 'Espace Multimedia';

// Endpoint AJAX pour charger les photos d'un album en lightbox
// DOIT etre avant include header.php pour eviter la pollution HTML dans le JSON
if (isset($_GET['ajax']) && $_GET['ajax'] === 'album') {
    $cat = trim($_GET['category'] ?? '');
    $album_id = intval($_GET['album_id'] ?? 0);
    if ($album_id > 0) {
        $catDb = null;
        $sCat = mysqli_prepare($con, "SELECT category FROM media WHERE id=? LIMIT 1");
        if ($sCat) {
            mysqli_stmt_bind_param($sCat, 'i', $album_id);
            mysqli_stmt_execute($sCat);
            mysqli_stmt_bind_result($sCat, $catDb);
            mysqli_stmt_fetch($sCat);
            mysqli_stmt_close($sCat);
        }
        if ($catDb !== null) {
            $cat = (string) $catDb;
        }
    }
    $photosAjax = [];
    $qAjax = mysqli_prepare(
    $con,
    "SELECT id, title, file_path
     FROM media
     WHERE ( (TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')) = TRIM(REPLACE(REPLACE(?, CHAR(160), ' '), '\t', ' ')))
             OR (category IS NULL AND TRIM(REPLACE(REPLACE(?, CHAR(160), ' '), '\t', ' ')) = '') )
       AND type='image'
       AND is_public=1
       AND status='published'
         ORDER BY created_at DESC
         LIMIT 100"
    );
    mysqli_stmt_bind_param($qAjax, 'ss', $cat, $cat);
    mysqli_stmt_execute($qAjax);
    $rAjax = emsp_stmt_fetch_all($qAjax);
    foreach ($rAjax as $row) {
        $photosAjax[] = [
            'id'    => (int)$row['id'],
            'title' => emsp_mediatheque_fix_text((string)($row['title'] ?? '')),
            'src'   => emsp_media_src((string)$row['file_path'])
        ];
    }
    mysqli_stmt_close($qAjax);
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['photos' => $photosAjax],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<style>
.multimedia-hero {
    background:
        radial-gradient(circle at top right, rgba(8,97,54,.12), transparent 24%),
        linear-gradient(180deg, #ffffff 0%, #f6faf8 100%);
    padding:2rem 0 1rem;
    border-bottom:2px solid rgba(8,97,54,.12);
}
.multimedia-hero-shell {
    position: relative;
    overflow: hidden;
    border-radius: 30px;
    padding: 2.4rem;
    color: #fff;
    background:
        linear-gradient(120deg, rgba(8,21,44,.8) 0%, rgba(8,34,65,.78) 40%, rgba(8,97,54,.52) 100%),
        url('assets/images/emsp-ivoire-tech-forum-2025.jpg') center 22% / cover no-repeat;
    box-shadow: var(--emsp-panel-shadow);
}
.multimedia-hero-shell::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgba(8,19,38,.86) 0%, rgba(8,19,38,.6) 44%, rgba(8,19,38,.24) 78%, rgba(8,19,38,.08) 100%);
    pointer-events: none;
}
.multimedia-hero-shell > * {
    position: relative;
    z-index: 1;
}
.multimedia-hero-head {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1.2rem;
    max-width: 44rem;
}
.multimedia-hero-copy {
    max-width: 42rem;
}
.multimedia-title { font-size:2.2rem; font-weight:900; color:#fff; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.3rem; }
.multimedia-subtitle { color:rgba(255,255,255,.84); font-size:.96rem; margin:0; max-width:38rem; }
.multimedia-hero-switch {
    display: flex;
    flex-wrap: wrap;
    gap: .7rem;
    align-items: center;
}
.btn-media-nav { background:rgba(8,97,54,.84); border:1px solid rgba(255,255,255,.14); color:#fff; font-size:.78rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; padding:.6rem 1.2rem; border-radius:999px; text-decoration:none; transition:background .15s, transform .15s, border-color .15s; box-shadow:0 14px 26px rgba(4,18,38,.16); }
.btn-media-nav:hover, .btn-media-nav.active { background:#086136; border-color:rgba(255,255,255,.22); color:#fff; transform:translateY(-1px); }

.section-phototheque { background:#f4faf6; padding:3rem 0; }
.section-phototheque {
    background:
        radial-gradient(circle at top right, rgba(8,97,54,.08), transparent 22%),
        linear-gradient(180deg, #f4faf6 0%, #f7fbf9 100%);
}
.section-phototheque .section-title,
.section-videotheque .section-title { font-size:1.6rem; font-weight:900; color:#086136; text-transform:uppercase; letter-spacing:.04em; margin-bottom:2rem; text-align:center; }
.albums-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1.5rem; }
@media (max-width: 768px){ .albums-grid{grid-template-columns:repeat(2,1fr);} }
@media (max-width: 480px){ .albums-grid{grid-template-columns:1fr;} }
.album-card { position:relative; background:#fff; border-radius:22px; overflow:hidden; border:1px solid #d6e3da; transition:transform .2s, box-shadow .2s, border-color .2s; box-shadow:0 16px 36px rgba(17,40,74,.06); }
.album-card:hover { transform:translateY(-4px); box-shadow:0 20px 42px rgba(17,40,74,.12); border-color:rgba(8,97,54,.26); }
.album-card-link { position:absolute; inset:0; z-index:1; border-radius:inherit; }
.album-cover { position:relative; height:200px; overflow:hidden; background:linear-gradient(160deg,#d8e7d8,#b8d2b8); border-bottom:1px solid rgba(8,97,54,.12); }
.album-cover img { width:100%; height:100%; object-fit:cover; object-position:center; display:block; transition:transform .3s ease; }
.album-card:hover .album-cover img { transform:scale(1.04); }
.album-overlay { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:linear-gradient(180deg, rgba(7,20,39,.08) 0%, rgba(7,20,39,.26) 100%); transition:background .2s; }
.album-card:hover .album-overlay { background:linear-gradient(180deg, rgba(7,20,39,.12) 0%, rgba(7,20,39,.38) 100%); }
.album-overlay i { font-size:2.5rem; color:rgba(255,255,255,.75); }
.album-cover-meta { position:absolute; left:12px; right:12px; bottom:10px; z-index:2; display:flex; align-items:flex-end; justify-content:space-between; gap:.6rem; pointer-events:none; }
.album-cover-title { max-width:min(72%,20rem); display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; font-size:.88rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#fff; text-shadow:0 4px 18px rgba(0,0,0,.65); }
.album-count { background:rgba(7,20,39,.78); color:#fff; font-size:.7rem; font-weight:700; padding:.28em .65em; border-radius:999px; display:flex; align-items:center; gap:.3rem; box-shadow:0 10px 22px rgba(0,0,0,.18); }
.album-info { position:relative; z-index:2; padding:.9rem 1rem 1rem; }
.album-title { font-size:.85rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#1a2540; line-height:1.3; display:-webkit-box; line-clamp:2; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.4rem; min-height:2.2em; }
.album-date { font-size:.75rem; color:#888; margin-bottom:.5rem; display:flex; align-items:center; gap:.35rem; }
.album-link { position:relative; z-index:2; font-size:.78rem; font-weight:700; color:#c8860a; text-decoration:none; display:flex; align-items:center; gap:.3rem; transition:gap .15s; }
.album-link:hover { color:#a06a00; gap:.55rem; }
.album-pagination { display:flex; justify-content:center; gap:.4rem; margin-top:2rem; }
.album-pagination a, .album-pagination span { width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:4px; font-size:.82rem; font-weight:600; text-decoration:none; border:1px solid #ddd; color:#333; background:#fff; transition:all .15s; }
.album-pagination a:hover { background:#086136; color:#fff; border-color:#086136; }
.album-pagination span.active { background:#086136; color:#fff; border-color:#086136; }

.album-photos-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
@media (max-width: 992px){ .album-photos-grid{grid-template-columns:repeat(3,1fr);} }
@media (max-width: 768px){ .album-photos-grid{grid-template-columns:repeat(2,1fr);} }
@media (max-width: 480px){ .album-photos-grid{grid-template-columns:1fr;} }
.photo-item { position:relative; cursor:pointer; }
.photo-item img { width:100%; height:100%; object-fit:cover; border-radius:6px; min-height:160px; background:#e9ecef; }
.photo-item .photo-caption { position:absolute; left:8px; bottom:8px; background:rgba(0,0,0,.55); color:#fff; padding:.25rem .55rem; border-radius:4px; font-size:.75rem; }

.section-videotheque { background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); padding:3rem 0; }
.video-layout { display:grid; grid-template-columns:65fr 35fr; gap:1.5rem; align-items:start; }
.video-layout > * { position:relative; }
@media (max-width: 768px){ .video-layout{grid-template-columns:1fr;} }
.video-title-main { font-size:1rem; font-weight:700; color:#1a2540; margin-top:.8rem; text-transform:uppercase; }
.video-desc-main { font-size:.82rem; color:#666; margin-top:.3rem; }
.playlist-box { position:relative; z-index:2; border:1px solid #d7e5df; border-radius:24px; overflow:hidden; box-shadow:0 18px 40px rgba(17,40,74,.08); background:linear-gradient(180deg,#ffffff 0%,#f8fcfa 100%); }
.playlist-header { background:linear-gradient(135deg, #10274a 0%, #1a3c6e 68%, #0a553e 100%); padding:.85rem 1rem; font-size:.8rem; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.04em; display:flex; justify-content:space-between; }
.playlist-scroll { max-height:520px; overflow-y:auto; }
.playlist-scroll::-webkit-scrollbar { width:4px; }
.playlist-scroll::-webkit-scrollbar-thumb { background:#ccc; border-radius:2px; }
.playlist-item { display:flex; gap:.7rem; padding:.75rem .9rem; cursor:pointer; border-bottom:1px solid #eef3f0; transition:background .15s, transform .15s, box-shadow .15s; align-items:center; position:relative; z-index:1; }
.playlist-item:hover { background:#f5f9f5; }
.playlist-item:focus-visible { outline:2px solid rgba(8,97,54,.4); outline-offset:-2px; }
.playlist-item.active { background:linear-gradient(90deg, rgba(8,97,54,.09) 0%, rgba(8,97,54,.03) 100%); box-shadow:inset 0 0 0 1px rgba(8,97,54,.18); }
.playlist-thumb { position:relative; width:96px; aspect-ratio:16/9; border-radius:12px; overflow:hidden; flex-shrink:0; background:#1a2540; border:1px solid rgba(255,255,255,.12); box-shadow:0 10px 18px rgba(12,25,49,.12); }
.playlist-thumb img,
.video-thumb-img { width:100%; height:100%; object-fit:cover; display:block; }
.playlist-play-icon { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.3); color:#fff; font-size:1.2rem; opacity:0; transition:opacity .15s; }
.playlist-item:hover .playlist-play-icon, .playlist-item.active .playlist-play-icon { opacity:1; }
.playlist-info { flex:1; min-width:0; }
.playlist-item-title { font-size:.75rem; font-weight:700; color:#1a2540; display:-webkit-box; line-clamp:2; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; line-height:1.35; }
.playlist-item.active .playlist-item-title { color:#086136; }
.playlist-item-meta { font-size:.68rem; color:#999; margin-top:.2rem; }
.video-thumb-local {
    position: relative;
    width: 100%;
    height: 100%;
    aspect-ratio: 16/9;
    background: linear-gradient(135deg, #1a2540 0%, #1a3c6e 100%);
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
}
.video-thumb-poster {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity .28s ease;
    z-index: 0;
}
.video-thumb-local.has-poster .video-thumb-poster {
    opacity: 1;
}
.video-thumb-local.has-poster .video-initiale {
    display: none;
}
.video-thumb-local.has-poster .video-thumb-bg {
    background: linear-gradient(180deg, rgba(9,22,44,.02) 0%, rgba(9,22,44,.34) 100%);
}
.video-thumb-bg {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    z-index: 1;
    background: linear-gradient(180deg, rgba(9,22,44,.1) 0%, rgba(9,22,44,.22) 100%);
}
.video-initiale {
    font-size: 3rem;
    font-weight: 900;
    color: rgba(255,255,255,0.15);
    line-height: 1;
}
.video-play-btn {
    position: absolute;
    font-size: 3.5rem;
    color: rgba(255,255,255,0.9);
    filter: drop-shadow(0 2px 8px rgba(0,0,0,0.4));
    transition: transform 0.2s, color 0.2s;
}
.video-thumb-local:hover .video-play-btn {
    transform: scale(1.15);
    color: #fff;
}
.video-thumb-title {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(180deg, rgba(7,20,39,0) 0%, rgba(7,20,39,.35) 38%, rgba(7,20,39,.92) 100%);
    color: #fff;
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1.35;
    padding: 1.8rem 0.8rem 0.65rem;
    text-shadow: 0 2px 12px rgba(0,0,0,.55);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.video-player-shell {
    position: relative;
    width: 100%;
    aspect-ratio: 16/9;
    border-radius: 24px;
    overflow: hidden;
    background: linear-gradient(135deg, #0f1c34 0%, #1a3c6e 100%);
    box-shadow: 0 24px 48px rgba(13,26,50,.18);
    border: 1px solid rgba(8,97,54,.18);
}
.video-player-shell iframe,
.video-player-shell video,
.video-player-local-placeholder {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}
.video-player-local-placeholder {
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.16), transparent 24%),
        linear-gradient(135deg, #101b31 0%, #1a3c6e 62%, #224f90 100%);
}
.video-player-local-card {
    position: relative;
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    overflow: hidden;
    border-radius: 14px;
    border: 0;
    padding: 0;
    background: transparent;
    cursor: pointer;
}
.video-player-poster {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
    transition: opacity .28s ease;
}
.video-player-local-card.has-poster .video-player-poster {
    opacity: 1;
}
.video-player-local-card.has-poster .video-player-initial {
    display: none;
}
.video-player-type {
    position: absolute;
    top: 1rem;
    left: 1rem;
    padding: .35rem .8rem;
    border-radius: 999px;
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.16);
    font-size: .78rem;
    font-weight: 700;
}
.video-player-initial {
    font-size: clamp(4rem, 10vw, 7rem);
    font-weight: 900;
    line-height: 1;
    color: rgba(255,255,255,.12);
}
.video-player-play {
    position: absolute;
    font-size: clamp(3rem, 6vw, 4.5rem);
    color: rgba(255,255,255,.92);
    filter: drop-shadow(0 8px 20px rgba(0,0,0,.28));
}
.video-player-title {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 2rem 1.1rem 1rem;
    font-size: .98rem;
    font-weight: 800;
    line-height: 1.35;
    text-shadow: 0 4px 18px rgba(0,0,0,.55);
    background: linear-gradient(180deg, rgba(7,20,39,0) 0%, rgba(7,20,39,.22) 30%, rgba(7,20,39,.86) 100%);
}
.video-player-local-card.is-loading::after {
    content: 'Chargement de la video...';
    position: absolute;
    left: 50%;
    bottom: 1.1rem;
    transform: translateX(-50%);
    padding: .45rem .8rem;
    border-radius: 999px;
    background: rgba(6,17,34,.72);
    border: 1px solid rgba(255,255,255,.16);
    font-size: .78rem;
    font-weight: 700;
    color: #fff;
}
@media (min-width: 1200px){
    .multimedia-hero { padding:2.5rem 0 1.5rem; }
    .multimedia-hero-shell { padding: 2.8rem 3rem; }
    .multimedia-hero-shell { min-height: 230px; display:flex; align-items:center; }
    .multimedia-hero-head,
    .multimedia-hero-copy { max-width: 100%; }
    .multimedia-title { font-size:2.5rem; }
    .multimedia-subtitle { font-size:1rem; max-width:48rem; }
    .video-layout { grid-template-columns:minmax(0,1.25fr) minmax(340px,.75fr); gap:2rem; }
    .playlist-scroll { max-height:610px; }
}

.lightbox-modal .modal-content { background:#000; }
.lightbox-modal img { max-height:88vh; }
.album-carousel-wrap { position:relative; }
.album-nav-btn { position:absolute; top:50%; transform:translateY(-50%); z-index:5; width:44px; height:44px; border-radius:50%; border:0; background:rgba(0,0,0,.55); color:#fff; display:flex; align-items:center; justify-content:center; }
.album-nav-btn:hover { background:rgba(0,0,0,.75); }
.album-nav-btn.prev { left:10px; }
.album-nav-btn.next { right:10px; }
.album-page-slider { position:relative; border-radius:10px; overflow:hidden; background:#000; }
.album-page-slider img { width:100%; max-height:60vh; object-fit:contain; background:#000; }
.album-page-btn { position:absolute; top:50%; transform:translateY(-50%); z-index:4; width:46px; height:46px; border-radius:50%; border:0; background:rgba(0,0,0,.55); color:#fff; display:flex; align-items:center; justify-content:center; }
.album-page-btn:hover { background:rgba(0,0,0,.75); }
.album-page-btn.prev { left:12px; }
.album-page-btn.next { right:12px; }
</style>

<section class="multimedia-hero">
    <div class="container">
        <div class="multimedia-hero-shell">
        <div class="multimedia-hero-head">
            <div class="multimedia-hero-copy">
                <h1 class="multimedia-title">Espace Multimedia</h1>
                <p class="multimedia-subtitle">Toutes nos videos, photos des evenements et activites de l'EMSP</p>
            </div>
            <div class="multimedia-hero-switch">
                <a href="#phototheque" class="btn-media-nav active" id="btn-photo">Phototheque</a>
                <a href="#videotheque" class="btn-media-nav" id="btn-video">Videotheque</a>
            </div>
        </div>
        </div>
    </div>
</section>

<?php if ($album_view !== ''): ?>
<section class="section-phototheque" id="phototheque">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
            <div>
                <h2 class="section-title text-start mb-1">Phototheque - <?= htmlspecialchars($album_view_label) ?></h2>
                <p class="text-muted mb-0">Clique sur une photo pour l'agrandir</p>
            </div>
            <a href="mediatheque.php#phototheque" class="btn btn-outline-success btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Retour aux albums
            </a>
        </div>

        <?php if (empty($album_photos)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-images media-empty-icon"></i>
                <p>Aucune photo dans cet album.</p>
            </div>
        <?php else: ?>
            <?php $showPageNav = count($album_photos) > 1; ?>
            <div class="album-page-slider mb-4">
                <div id="albumPageCarousel"
                     class="carousel slide"
                     data-bs-ride="carousel"
                     data-bs-interval="4600"
                     data-bs-touch="true"
                     data-bs-pause="false">
                    <div class="carousel-inner">
                        <?php foreach ($album_photos as $i => $p): ?>
                            <div class="carousel-item<?= $i === 0 ? ' active' : '' ?>">
                                <div class="d-flex justify-content-center">
                                    <img src="<?= htmlspecialchars($p['src']) ?>" alt="<?= htmlspecialchars($p['title'] ?? 'Photo') ?>">
                                </div>
                                <?php if (!empty($p['title'])): ?>
                                    <div class="text-center text-muted py-2"><?= htmlspecialchars($p['title']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($showPageNav): ?>
                    <button class="album-page-btn prev" type="button" data-bs-target="#albumPageCarousel" data-bs-slide="prev"><i class="bi bi-chevron-left"></i></button>
                    <button class="album-page-btn next" type="button" data-bs-target="#albumPageCarousel" data-bs-slide="next"><i class="bi bi-chevron-right"></i></button>
                <?php endif; ?>
            </div>

            <div class="album-photos-grid">
                <?php foreach ($album_photos as $p): ?>
                    <div class="photo-item" data-src="<?= htmlspecialchars($p['src']) ?>" data-title="<?= htmlspecialchars($p['title'] ?? '') ?>">
                        <img src="<?= htmlspecialchars($p['src']) ?>" alt="<?= htmlspecialchars($p['title'] ?? 'Photo') ?>" loading="lazy">
                        <?php if (!empty($p['title'])): ?>
                        <span class="photo-caption"><?= htmlspecialchars($p['title']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>
</section>

<?php else: ?>
<section class="section-phototheque" id="phototheque">
    <div class="container">
        <h2 class="section-title">Phototheque</h2>

        <?php if (empty($albums)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-images media-empty-icon"></i>
                <p>Aucune photo disponible.<br>
                   <a href="admin/mediatheque.php">Ajouter des photos &rarr;</a></p>
            </div>
        <?php else: ?>
            <div class="albums-grid"
                 data-emsp-carousel="1"
                 data-emsp-carousel-style="immersive"
                 data-emsp-carousel-title="Albums de la mediatheque EMSP"
                 data-emsp-carousel-interval="4700"
                 data-emsp-per-view-desktop="3"
                 data-emsp-per-view-tablet="2"
                 data-emsp-per-view-mobile="1">
                <?php foreach ($albums as $alb): ?>
                    <?php $albumHref = 'mediatheque.php?album=' . urlencode((string) $alb['category_raw']) . '#phototheque'; ?>
                    <div class="album-card">
                        <a class="album-card-link album-open-trigger"
                           href="<?= $albumHref ?>"
                           data-category="<?= htmlspecialchars((string)$alb['category_raw']) ?>"
                           data-album-id="<?= (int) ($alb['cover_id'] ?? 0) ?>"
                           data-category-label="<?= htmlspecialchars((string)$alb['category_label']) ?>"
                           aria-label="Ouvrir l album <?= htmlspecialchars((string)$alb['category_label']) ?>"></a>
                        <div class="album-cover">
                            <img src="<?= htmlspecialchars($alb['cover_src']) ?>"
                                 alt="<?= htmlspecialchars($alb['category_label']) ?>"
                                 loading="lazy"
                                 data-fallback-src="assets/images/video-placeholder.jpg">
                            <div class="album-overlay"><i class="bi bi-image"></i></div>
                            <div class="album-cover-meta">
                                <span class="album-cover-title"><?= htmlspecialchars(mb_strtoupper($alb['category_label'])) ?></span>
                                <span class="album-count"><i class="bi bi-images"></i> <?= (int)$alb['nb_photos'] ?></span>
                            </div>
                        </div>
                        <div class="album-info">
                            <h3 class="album-title"><?= htmlspecialchars(mb_strtoupper($alb['category_label'])) ?></h3>
                            <div class="album-date"><i class="bi bi-calendar3"></i><?= emsp_date_fr((string)$alb['last_date']) ?></div>
                            <a class="album-link album-open-trigger"
                               href="<?= $albumHref ?>"
                               data-category="<?= htmlspecialchars((string)$alb['category_raw']) ?>"
                               data-album-id="<?= (int) ($alb['cover_id'] ?? 0) ?>"
                               data-category-label="<?= htmlspecialchars((string)$alb['category_label']) ?>">
                                Voir les photos <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="section-videotheque" id="videotheque">
    <div class="container">
        <h2 class="section-title">Videotheque</h2>

        <?php if (empty($videos)): ?>
            <div class="text-center py-4 text-muted">
                <i class="bi bi-camera-video media-empty-icon"></i>
                <p>Aucune video disponible.</p>
            </div>
        <?php else: ?>
            <?php $first = $videos[0]; ?>
            <div class="video-layout">
                <div>
                    <div id="main-player" class="video-player-shell">
                        <?php if ($first['is_youtube']): ?>
                            <iframe id="player-iframe"
                                    src="<?= htmlspecialchars($first['embed']) ?>"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                    allowfullscreen
                                    class="video-frame-visible"></iframe>
                            <div id="player-local-placeholder" class="video-player-local-placeholder"></div>
                            <video id="player-video" controls preload="none" playsinline class="video-frame-hidden"></video>
                        <?php else: ?>
                            <iframe id="player-iframe" class="video-frame-hidden"></iframe>
                            <div id="player-local-placeholder" class="video-player-local-placeholder video-placeholder-visible">
                                <?= emsp_video_player_placeholder($first) ?>
                            </div>
                            <video id="player-video" controls preload="none" playsinline class="video-frame-hidden"></video>
                        <?php endif; ?>
                    </div>
                    <div id="player-title" class="video-title-main mt-2"><?= htmlspecialchars($first['title'] ?: 'Video EMSP') ?></div>
                    <?php if (!empty($first['description'])): ?>
                        <div id="player-desc" class="video-desc-main"><?= htmlspecialchars($first['description']) ?></div>
                    <?php else: ?>
                        <div id="player-desc" class="video-desc-main text-muted"></div>
                    <?php endif; ?>
                </div>

                <div class="playlist-box">
                    <div class="playlist-header">
                        <span>Liste de lecture</span>
                        <span><?= (int) $total_videos ?> videos</span>
                    </div>
                    <div class="playlist-scroll">
                        <?php foreach ($videos as $i => $v): ?>
                            <?php
                                $isActive = $i === 0;
                                $type = $v['is_youtube'] ? 'youtube' : 'video';
                                $src  = $v['is_youtube'] ? $v['embed'] : emsp_media_src((string)$v['file_path']);
                                $titleShort = mb_strlen($v['title'] ?? '') > 70 ? mb_substr($v['title'],0,70).'...' : ($v['title'] ?? 'Video EMSP');
                            ?>
                            <div class="playlist-item <?= $isActive ? 'active' : '' ?>"
                                 data-src="<?= htmlspecialchars($src) ?>"
                                 data-type="<?= $type ?>"
                                 data-title="<?= htmlspecialchars($v['title'] ?: 'Video EMSP') ?>"
                                 data-desc="<?= htmlspecialchars($v['description'] ?? '') ?>"
                                 data-initial="<?= htmlspecialchars($v['initial'] ?? 'V') ?>"
                                 data-cache-key="<?= htmlspecialchars($v['cache_key'] ?? '') ?>"
                                 data-poster="<?= htmlspecialchars(emsp_video_poster_src($v)) ?>"
                                 role="button"
                                 tabindex="0">
                                <div class="playlist-thumb">
                                    <?= emsp_video_card_thumb($v) ?>
                                    <div class="playlist-play-icon"><i class="bi bi-play-circle-fill"></i></div>
                                </div>
                                <div class="playlist-info">
                                    <div class="playlist-item-title"><?= htmlspecialchars($titleShort) ?></div>
                                    <div class="playlist-item-meta"><?= emsp_date_fr((string)$v['created_at']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Lightbox pour photos album -->
<div class="modal fade lightbox-modal" id="photoLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header border-0">
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body d-flex align-items-center justify-content-center position-relative">
                <button class="btn btn-outline-light position-absolute start-0 ms-3" type="button" id="lbPrev"><i class="bi bi-chevron-left"></i></button>
                <img id="lbImage" src="assets/images/video-placeholder.jpg" alt="Photo" class="img-fluid rounded lb-image-max">
                <button class="btn btn-outline-light position-absolute end-0 me-3" type="button" id="lbNext"><i class="bi bi-chevron-right"></i></button>
            </div>
            <div class="modal-footer border-0 justify-content-center text-white">
                <div id="lbCaption"></div>
            </div>
        </div>
    </div>
</div>

<!-- Modal album (photos defilantes) -->
<div class="modal fade" id="albumModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="albumModalTitle">Photos de l'album</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div class="album-carousel-wrap">
          <button class="album-nav-btn prev" type="button" id="albumPrevBtn"><i class="bi bi-chevron-left"></i></button>
          <button class="album-nav-btn next" type="button" id="albumNextBtn"><i class="bi bi-chevron-right"></i></button>
          <div id="albumCarousel" class="carousel slide" data-bs-ride="false" data-bs-interval="false">
          <div class="carousel-inner" id="albumCarouselInner">
            <div class="carousel-item active text-center py-5" id="albumLoader">
                <div class="spinner-border text-success"></div>
            </div>
          </div>
          <button class="carousel-control-prev" type="button" data-bs-target="#albumCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Precedent</span>
          </button>
          <button class="carousel-control-next" type="button" data-bs-target="#albumCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Suivant</span>
          </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
// Preparer le JSON pour la lightbox seulement en mode album detail
if ($album_view !== '' && !empty($album_photos)) {
    $photos_js = [];
    foreach ($album_photos as $p) {
        $photos_js[] = [
            'src' => $p['src'],
            'title' => $p['title'] ?? ''
        ];
    }
    $photos_json = json_encode($photos_js, JSON_UNESCAPED_UNICODE);
} else {
    $photos_json = '[]';
}
?>

<?php
ob_start();
?>
<script>
(function () {
  var video = document.getElementById('player-video');
  var placeholder = document.getElementById('player-local-placeholder');
  var iframe = document.getElementById('player-iframe');
  var mainPlayer = document.getElementById('main-player');
  var posterCache = new Map();
  if (!video || !placeholder || !mainPlayer) return;

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getPosterUrl(container) {
    if (!container) return '';
    var dataPoster = container.getAttribute('data-video-poster') || '';
    if (dataPoster) return dataPoster;
    var img = container.querySelector('.video-thumb-poster, .video-player-poster');
    return img ? (img.getAttribute('src') || '') : '';
  }

  function getCacheKey(container, fallbackSrc) {
    if (!container) {
      return fallbackSrc || '';
    }
    return container.getAttribute('data-video-cache-key') || fallbackSrc || '';
  }

  function readStoredPoster(cacheKey) {
    if (!cacheKey) return '';
    try {
      return sessionStorage.getItem('emsp_video_poster_' + cacheKey) || '';
    } catch (error) {
      return '';
    }
  }

  function storePoster(cacheKey, posterUrl) {
    if (!cacheKey || !posterUrl) return;
    try {
      sessionStorage.setItem('emsp_video_poster_' + cacheKey, posterUrl);
    } catch (error) {
      // no-op
    }
  }

  function applyPoster(container, posterUrl) {
    if (!container || !posterUrl) return;
    var img = container.querySelector('.video-thumb-poster, .video-player-poster');
    if (!img) return;
    img.setAttribute('src', posterUrl);
    container.classList.add('has-poster');
  }

  function cleanupPosterLoader(loader) {
    if (!loader) return;
    try {
      loader.pause();
      loader.removeAttribute('src');
      loader.load();
    } catch (error) {
      // no-op
    }
    if (loader.parentNode) {
      loader.parentNode.removeChild(loader);
    }
  }

  function ensureVideoPoster(src, cacheKey) {
    if (!src) {
      return Promise.resolve('');
    }
    var key = cacheKey || src;
    var stored = readStoredPoster(key);
    if (stored) {
      posterCache.set(src, stored);
      return Promise.resolve(stored);
    }
    if (posterCache.has(src)) {
      return Promise.resolve(posterCache.get(src));
    }

    return new Promise(function (resolve) {
      var loader = document.createElement('video');
      loader.preload = 'metadata';
      loader.muted = true;
      loader.playsInline = true;
      loader.crossOrigin = 'anonymous';
      loader.style.position = 'absolute';
      loader.style.width = '1px';
      loader.style.height = '1px';
      loader.style.opacity = '0';
      loader.style.pointerEvents = 'none';
      loader.setAttribute('aria-hidden', 'true');
      document.body.appendChild(loader);

      var settled = false;
      function done(url) {
        if (settled) return;
        settled = true;
        cleanupPosterLoader(loader);
        if (url) {
          posterCache.set(src, url);
          storePoster(key, url);
        }
        resolve(url || '');
      }

      function captureFrame() {
        try {
          var canvas = document.createElement('canvas');
          canvas.width = loader.videoWidth || 640;
          canvas.height = loader.videoHeight || 360;
          var ctx = canvas.getContext('2d');
          if (!ctx) {
            done('');
            return;
          }
          ctx.drawImage(loader, 0, 0, canvas.width, canvas.height);
          done(canvas.toDataURL('image/jpeg', 0.86));
        } catch (error) {
          done('');
        }
      }

      loader.addEventListener('loadedmetadata', function () {
        var duration = Number(loader.duration || 0);
        if (!isFinite(duration) || duration <= 0) {
          captureFrame();
          return;
        }
        var target = Math.min(1, Math.max(duration * 0.1, 0.12));
        try {
          loader.currentTime = target;
        } catch (error) {
          captureFrame();
        }
      }, { once: true });

      loader.addEventListener('seeked', captureFrame, { once: true });
      loader.addEventListener('error', function () {
        done('');
      }, { once: true });

      window.setTimeout(function () {
        done('');
      }, 4000);

      loader.src = src;
      loader.load();
    });
  }

  function renderLocalPlaceholder(letter, label, src, posterUrl, cacheKey) {
    var safeLetter = escapeHtml(letter);
    var safeLabel = escapeHtml(label || 'Video EMSP');
    var safeSrc = escapeHtml(src);
    var safePoster = escapeHtml(posterUrl || '');
    var safeCacheKey = escapeHtml(cacheKey || '');
    var hasPosterClass = safePoster !== '' ? ' has-poster' : '';
    var posterMarkup = '<img class="video-player-poster" alt="' + safeLabel + '"' + (safePoster !== '' ? ' src="' + safePoster + '"' : '') + '>';

    return ''
      + '<button type="button" class="video-player-local-card' + hasPosterClass + '" data-local-player-trigger data-video-src="' + safeSrc + '" data-video-title="' + safeLabel + '" data-video-initial="' + safeLetter + '" data-video-cache-key="' + safeCacheKey + '" data-video-poster="' + safePoster + '">'
      + posterMarkup
      + '<span class="video-player-type">Video EMSP</span>'
      + '<span class="video-player-initial">' + safeLetter + '</span>'
      + '<div class="video-player-play"><i class="bi bi-play-circle-fill"></i></div>'
      + '<div class="video-player-title">' + safeLabel + '</div>'
      + '</button>';
  }

  function showLocalPlaceholder() {
    video.style.display = 'none';
    if (placeholder.innerHTML.trim() !== '') {
      placeholder.style.display = 'flex';
    }
  }

  function revealVideo() {
    if (iframe && window.getComputedStyle(iframe).display !== 'none') {
      return;
    }
    video.style.display = 'block';
    placeholder.style.display = 'none';
    var trigger = placeholder.querySelector('[data-local-player-trigger]');
    if (trigger) {
      trigger.classList.remove('is-loading');
    }
  }

  function hideYoutubePlayer() {
    if (!iframe) return;
    iframe.style.display = 'none';
    iframe.src = '';
  }

  function stopLocalPlayer() {
    try {
      video.pause();
      video.currentTime = 0;
    } catch (error) {
      // no-op
    }
    video.removeAttribute('src');
    video.removeAttribute('poster');
    video.load();
    video.style.display = 'none';
  }

  function bindLocalPlayerTrigger() {
    var trigger = placeholder.querySelector('[data-local-player-trigger]');
    if (!trigger || trigger.dataset.bound === '1') {
      return;
    }
    trigger.dataset.bound = '1';
    function startLocalPlayback() {
      var src = trigger.dataset.videoSrc || '';
      var cacheKey = trigger.dataset.videoCacheKey || src;
      if (!src) return;

      var posterUrl = getPosterUrl(trigger);
      trigger.classList.add('is-loading');
      hideYoutubePlayer();
      stopLocalPlayer();

      if (posterUrl) {
        video.setAttribute('poster', posterUrl);
        video.style.display = 'block';
        placeholder.style.display = 'none';
      } else {
        video.removeAttribute('poster');
        showLocalPlaceholder();
      }

      video.src = src;
      video.load();
      var playPromise = video.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(function () {
          trigger.classList.remove('is-loading');
          showLocalPlaceholder();
        });
      }
    }

    trigger.addEventListener('click', startLocalPlayback);
    trigger.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      event.preventDefault();
      startLocalPlayback();
    });

    var triggerPoster = getPosterUrl(trigger);
    if (triggerPoster) {
      applyPoster(trigger, triggerPoster);
    }
  }

  function hydrateLocalThumb(card) {
    if (!card) return;
    var src = card.dataset.videoSrc || '';
    var cacheKey = card.dataset.videoCacheKey || src;
    if (!src) return;

    var cachedPoster = posterCache.get(src) || readStoredPoster(cacheKey) || getPosterUrl(card) || '';
    if (cachedPoster) {
      posterCache.set(src, cachedPoster);
      applyPoster(card, cachedPoster);
      return;
    }

    ensureVideoPoster(src, cacheKey).then(function (generatedPoster) {
      if (!generatedPoster) return;
      applyPoster(card, generatedPoster);
    });
  }

  function bootstrapThumbHydration() {
    var thumbCards = document.querySelectorAll('[data-local-thumb]');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          hydrateLocalThumb(entry.target);
          io.unobserve(entry.target);
        });
      }, { rootMargin: '160px 0px' });
      thumbCards.forEach(function (card) {
        io.observe(card);
      });
    } else {
      thumbCards.forEach(hydrateLocalThumb);
    }
  }

  function activatePlaylistItem(item) {
    if (!item) {
      return;
    }
    document.querySelectorAll('.playlist-item').forEach(function (el) {
      el.classList.remove('active');
    });
    item.classList.add('active');

    var src = item.dataset.src || '';
    var type = item.dataset.type || 'video';
    var title = item.dataset.title || '';
    var desc = item.dataset.desc || '';
    var initial = item.dataset.initial || 'V';
    var cacheKey = item.dataset.cacheKey || src;

    document.getElementById('player-title').textContent = title;
    document.getElementById('player-desc').textContent = desc;

    if (type === 'youtube') {
      stopLocalPlayer();
      placeholder.style.display = 'none';
      placeholder.innerHTML = '';
      if (iframe) {
        iframe.src = src;
        iframe.style.display = 'block';
      }
    } else {
      var posterUrl = getPosterUrl(item);
      hideYoutubePlayer();
      stopLocalPlayer();
      placeholder.innerHTML = renderLocalPlaceholder(initial, title || 'Video EMSP', src, posterUrl, cacheKey);
      placeholder.style.display = 'flex';
      bindLocalPlayerTrigger();

    }

    mainPlayer.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function bindPlaylistItems() {
    document.querySelectorAll('.playlist-item').forEach(function (item) {
      if (item.dataset.bound === '1') {
        return;
      }
      item.dataset.bound = '1';
      item.addEventListener('click', function () {
        activatePlaylistItem(item);
      });
      item.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        activatePlaylistItem(item);
      });
    });
  }

  window.switchVideo = activatePlaylistItem;

  video.addEventListener('loadeddata', revealVideo);
  video.addEventListener('canplay', revealVideo);
  video.addEventListener('error', function () {
    showLocalPlaceholder();
    var titleEl = document.getElementById('player-title');
    var descEl = document.getElementById('player-desc');
    if (descEl && descEl.textContent.trim() === '') {
      descEl.textContent = 'La video n a pas pu etre lue pour le moment.';
    }
    if (titleEl && titleEl.textContent.trim() === '') {
      titleEl.textContent = 'Lecture indisponible';
    }
  });

  bindLocalPlayerTrigger();
  bindPlaylistItems();
  bootstrapThumbHydration();

  var initialTrigger = placeholder.querySelector('[data-local-player-trigger]');
  if (initialTrigger) {
    hydrateLocalThumb(initialTrigger);
  }
})();

// Lightbox photos
(function(){
    const photos = <?= $photos_json ?>;
    if (!photos.length) return;
    let lbIndex = 0;
    const lbModal = new bootstrap.Modal(document.getElementById('photoLightbox'));
    const lbImage = document.getElementById('lbImage');
    const lbCaption = document.getElementById('lbCaption');
    function show(i){
        lbIndex = (i + photos.length) % photos.length;
        lbImage.src = photos[lbIndex].src;
        lbCaption.textContent = photos[lbIndex].title || '';
    }
    document.querySelectorAll('.photo-item').forEach((el, idx)=>{
        el.addEventListener('click', ()=>{
            show(idx); lbModal.show();
        });
    });
    document.getElementById('lbPrev').addEventListener('click', ()=>show(lbIndex-1));
    document.getElementById('lbNext').addEventListener('click', ()=>show(lbIndex+1));
    document.addEventListener('keydown', (e)=>{
        if (!document.getElementById('photoLightbox').classList.contains('show')) return;
        if (e.key === 'ArrowLeft') show(lbIndex-1);
        if (e.key === 'ArrowRight') show(lbIndex+1);
    });
})();

// Ouverture d'un album en modal avec defilement
(function(){
    if (typeof bootstrap === 'undefined') {
        return;
    }
    const modal = new bootstrap.Modal(document.getElementById('albumModal'));
    const inner = document.getElementById('albumCarouselInner');
    const title = document.getElementById('albumModalTitle');
    const prevBtn = document.getElementById('albumPrevBtn');
    const nextBtn = document.getElementById('albumNextBtn');
    const carouselEl = document.getElementById('albumCarousel');
    const carousel = carouselEl ? bootstrap.Carousel.getOrCreateInstance(carouselEl, { interval: false }) : null;
    if (prevBtn) {
        prevBtn.addEventListener('click', () => { if (carousel) { carousel.prev(); } });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => { if (carousel) { carousel.next(); } });
    }
    document.querySelectorAll('.album-open-trigger').forEach(link => {
        link.addEventListener('click', function(e){
            const href = this.getAttribute('href') || '';
            e.preventDefault();
            const cat = this.dataset.category || '';
            const albumId = this.dataset.albumId || '';
            const label = this.dataset.categoryLabel || cat;
            title.textContent = label !== '' ? ('Album - ' + label) : 'Album (sans titre)';
            inner.innerHTML = '<div class="carousel-item active text-center py-5" id="albumLoader"><div class="spinner-border text-success"></div></div>';
            modal.show();
            if (prevBtn) prevBtn.style.display = 'none';
            if (nextBtn) nextBtn.style.display = 'none';
            const params = new URLSearchParams();
            params.set('ajax', 'album');
            if (albumId !== '') {
                params.set('album_id', albumId);
            }
            params.set('category', cat);
            fetch('mediatheque.php?' + params.toString(), { credentials: 'include' })
                .then(r => r.json())
                .then(data => {
                    const photos = data.photos || [];
                    if (!photos.length) {
                        inner.innerHTML = '<div class="carousel-item active text-center py-5"><p class="text-muted mb-0">Aucune photo dans cet album.</p></div>';
                        return;
                    }
                    inner.innerHTML = '';
                    photos.forEach((p, idx) => {
                        const item = document.createElement('div');
                        item.className = 'carousel-item' + (idx === 0 ? ' active' : '');
                        item.innerHTML = `
                            <div class="d-flex justify-content-center">
                                <img src="${p.src}" alt="${p.title || ''}" class="d-block album-modal-image">
                            </div>
                            ${p.title ? `<div class="text-center mt-2 text-muted">${p.title}</div>` : ''}
                        `;
                        inner.appendChild(item);
                    });
                    if (prevBtn && nextBtn) {
                        const showNav = photos.length > 1;
                        prevBtn.style.display = showNav ? 'flex' : 'none';
                        nextBtn.style.display = showNav ? 'flex' : 'none';
                    }
                })
                .catch(()=>{
                    if (href !== '') {
                        window.location.href = href;
                        return;
                    }
                    inner.innerHTML = '<div class="carousel-item active text-center py-5"><p class="text-muted mb-0">Impossible de charger cet album.</p></div>';
                });
        });
    });
})();

</script>
<?php
$page_scripts = ob_get_clean();
include __DIR__ . '/includes/footer.php';
?>
