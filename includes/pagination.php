<?php
function render_pagination(int $currentPage, int $totalPages, string $baseUrl): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $currentPage = max(1, min($currentPage, $totalPages));
    $cleanBase = preg_replace('/([?&])page=\d+(&?)/i', '$1', $baseUrl) ?? $baseUrl;
    $cleanBase = rtrim((string) $cleanBase, '?&');

    $buildUrl = static function (int $page) use ($cleanBase): string {
        $separator = strpos($cleanBase, '?') === false ? '?' : '&';
        return $cleanBase . $separator . 'page=' . $page;
    };

    $windowStart = max(1, $currentPage - 2);
    $windowEnd = min($totalPages, $currentPage + 2);
    $pages = [1];

    for ($i = $windowStart; $i <= $windowEnd; $i++) {
        $pages[] = $i;
    }
    $pages[] = $totalPages;
    $pages = array_values(array_unique($pages));
    sort($pages);

    $html = '<nav aria-label="Pagination"><ul class="pagination justify-content-center mt-4">';

    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $html .= '<li class="page-item' . $prevDisabled . '">';
    if ($currentPage <= 1) {
        $html .= '<span class="page-link" aria-disabled="true">Prec.</span>';
    } else {
        $html .= '<a class="page-link" href="' . htmlspecialchars($buildUrl($currentPage - 1), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" aria-label="Page precedente">Prec.</a>';
    }
    $html .= '</li>';

    $lastRendered = null;
    foreach ($pages as $pageNumber) {
        if ($lastRendered !== null && $pageNumber > $lastRendered + 1) {
            $html .= '<li class="page-item disabled"><span class="page-link" aria-hidden="true">...</span></li>';
        }

        $activeClass = $pageNumber === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $activeClass . '">';
        if ($pageNumber === $currentPage) {
            $html .= '<span class="page-link" aria-current="page">' . $pageNumber . '</span>';
        } else {
            $html .= '<a class="page-link" href="' . htmlspecialchars($buildUrl($pageNumber), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">' . $pageNumber . '</a>';
        }
        $html .= '</li>';
        $lastRendered = $pageNumber;
    }

    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $html .= '<li class="page-item' . $nextDisabled . '">';
    if ($currentPage >= $totalPages) {
        $html .= '<span class="page-link" aria-disabled="true">Suiv.</span>';
    } else {
        $html .= '<a class="page-link" href="' . htmlspecialchars($buildUrl($currentPage + 1), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" aria-label="Page suivante">Suiv.</a>';
    }
    $html .= '</li>';

    $html .= '</ul></nav>';
    return $html;
}


