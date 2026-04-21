<?php

if (!function_exists('emsp_journal_fix_paths')) {
    function emsp_journal_fix_paths(string $html): string
    {
        return str_replace(
            ['../uploads/', '..\\uploads\\', '../assets/', '..\\assets\\'],
            ['uploads/', 'uploads/', 'assets/', 'assets/'],
            $html
        );
    }
}

if (!function_exists('emsp_journal_has_column')) {
    function emsp_journal_has_column(mysqli $con, string $column): bool
    {
        static $cache = [];
        $key = strtolower($column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        // Maintenance: cache a lightweight SHOW COLUMNS probe instead of hitting information_schema on each request.
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($safeColumn === '') {
            $cache[$key] = false;
            return false;
        }

        $result = @mysqli_query($con, "SHOW COLUMNS FROM `journal` LIKE '" . mysqli_real_escape_string($con, $safeColumn) . "'");
        $cache[$key] = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
        return $cache[$key];
    }
}

if (!function_exists('emsp_journal_parse_datetime_input')) {
    function emsp_journal_parse_datetime_input(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value);
        } catch (Exception $e) {
            return null;
        }

        return $dt->format('Y-m-d H:i:s');
    }
}

if (!function_exists('emsp_journal_datetime_local_value')) {
    function emsp_journal_datetime_local_value(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return '';
        }
    }
}

if (!function_exists('emsp_journal_format_datetime')) {
    function emsp_journal_format_datetime(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (Exception $e) {
            return '';
        }
    }
}

if (!function_exists('emsp_journal_relative_date')) {
    function emsp_journal_relative_date(?string $date): string
    {
        if (!$date) {
            return '';
        }
        try {
            $dt = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        } catch (Exception $e) {
            return '';
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $dt->getTimestamp();
        if ($diff < 60) {
            return "il y a moins d'une minute";
        }
        if ($diff < 3600) {
            return 'il y a ' . (int) ($diff / 60) . ' min';
        }
        if ($diff < 86400) {
            return 'il y a ' . (int) ($diff / 3600) . ' h';
        }
        if ($diff < 7 * 86400) {
            return 'il y a ' . (int) ($diff / 86400) . ' jours';
        }
        return 'le ' . $dt->format('d/m/Y');
    }
}

if (!function_exists('emsp_journal_excerpt')) {
    function emsp_journal_excerpt(string $text, int $max = 160): string
    {
        $clean = trim(strip_tags(function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($text) : $text));
        if (mb_strlen($clean) <= $max) {
            return $clean;
        }
        return rtrim(mb_substr($clean, 0, max(1, $max - 1))) . '...';
    }
}

if (!function_exists('emsp_journal_type_meta')) {
    function emsp_journal_type_meta(string $type): array
    {
        $map = [
            'annonce' => ['label' => 'Annonce', 'color' => '#004D2A', 'class' => 'journal-card-annonce'],
            'defi' => ['label' => 'Defi', 'color' => '#D4900A', 'class' => 'journal-card-defi'],
            'sondage' => ['label' => 'Sondage', 'color' => '#006B3C', 'class' => 'journal-card-sondage'],
        ];

        $type = strtolower(trim($type));
        return $map[$type] ?? ['label' => 'Article', 'color' => '#004D2A', 'class' => 'journal-card-annonce'];
    }
}

if (!function_exists('emsp_journal_cover_src')) {
    function emsp_journal_cover_src(string $html): string
    {
        $decoded = htmlspecialchars_decode((function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($html) : $html), ENT_QUOTES | ENT_HTML5);
        $decoded = emsp_journal_fix_paths($decoded);

        if (!preg_match('/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $decoded, $match)) {
            return '';
        }

        $src = trim((string) ($match[1] ?: ($match[2] ?: ($match[3] ?? ''))));
        if ($src === '' || preg_match('#^(?:javascript:|data:)#i', $src)) {
            return '';
        }

        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }

        $src = ltrim(str_replace('\\', '/', $src), '/');
        return (strpos($src, 'uploads/') === 0 || strpos($src, 'assets/') === 0) ? $src : '';
    }
}

if (!function_exists('emsp_journal_cover_html')) {
    function emsp_journal_cover_html(array $item, string $variant = 'feature'): string
    {
        $title = trim((string) ($item['title'] ?? 'Article'));
        $meta = emsp_journal_type_meta((string) ($item['type'] ?? ''));
        $typeKey = strtolower(trim((string) ($item['type'] ?? 'annonce')));
        if (!in_array($typeKey, ['annonce', 'defi', 'sondage'], true)) {
            $typeKey = 'annonce';
        }
        $cover = emsp_journal_cover_src((string) ($item['content'] ?? ''));
        $classes = 'journal-cover journal-cover-' . $variant;

        if ($cover !== '') {
            return '<div class="' . htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
                . '<img src="' . htmlspecialchars($cover, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" alt="' . htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" class="journal-cover-img">'
                . '</div>';
        }

        $seed = $title !== '' ? $title : $meta['label'];
        $initial = mb_strtoupper(mb_substr($seed, 0, 1, 'UTF-8'), 'UTF-8');

        return '<div class="' . htmlspecialchars($classes, ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' journal-cover-placeholder journal-cover-' . htmlspecialchars($typeKey, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
            . '<span class="journal-cover-type">' . htmlspecialchars($meta['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>'
            . '<span class="journal-cover-initial">' . htmlspecialchars($initial, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>'
            . '</div>';
    }
}

if (!function_exists('emsp_journal_normalize_class_list')) {
    function emsp_journal_normalize_class_list(string $classValue, bool $isImage = false): string
    {
        $allowed = [
            'ql-align-center',
            'ql-align-right',
            'ql-align-justify',
            'emsp-rich-img',
            'emsp-rich-img-left',
            'emsp-rich-img-center',
            'emsp-rich-img-right',
        ];
        $classes = preg_split('/\s+/', trim($classValue)) ?: [];
        $kept = [];
        foreach ($classes as $class) {
            if ($class === '') {
                continue;
            }
            if (in_array($class, $allowed, true)) {
                if (!$isImage && strpos($class, 'emsp-rich-img') === 0) {
                    continue;
                }
                $kept[$class] = true;
            }
        }
        return implode(' ', array_keys($kept));
    }
}

if (!function_exists('emsp_journal_sanitize_color_value')) {
    function emsp_journal_sanitize_color_value(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
            return $value;
        }
        if (preg_match('/^rgba?\(\s*[\d\s.,%]+\)$/i', $value)) {
            return $value;
        }
        return null;
    }
}

if (!function_exists('emsp_journal_sanitize_style')) {
    function emsp_journal_sanitize_style(string $style, bool $isImage = false): string
    {
        $allowed = [];
        foreach (explode(';', $style) as $rule) {
            $rule = trim($rule);
            if ($rule === '' || strpos($rule, ':') === false) {
                continue;
            }

            [$prop, $value] = array_map('trim', explode(':', $rule, 2));
            $prop = strtolower($prop);
            $valueOut = null;

            if (in_array($prop, ['text-align'], true) && in_array(strtolower($value), ['left', 'right', 'center', 'justify'], true)) {
                $valueOut = strtolower($value);
            } elseif (in_array($prop, ['color', 'background-color'], true)) {
                $valueOut = emsp_journal_sanitize_color_value($value);
            } elseif ($isImage && in_array($prop, ['width', 'max-width', 'height'], true) && preg_match('/^\d+(?:\.\d+)?(?:px|%)$|^auto$/i', $value)) {
                $valueOut = strtolower($value);
            } elseif ($isImage && $prop === 'float' && in_array(strtolower($value), ['left', 'right', 'none'], true)) {
                $valueOut = strtolower($value);
            } elseif ($isImage && $prop === 'display' && in_array(strtolower($value), ['block', 'inline-block', 'inline'], true)) {
                $valueOut = strtolower($value);
            } elseif ($isImage && in_array($prop, ['margin', 'margin-left', 'margin-right'], true) && preg_match('/^(?:0|auto|\d+(?:\.\d+)?px)(?:\s+(?:0|auto|\d+(?:\.\d+)?px)){0,3}$/i', $value)) {
                $valueOut = preg_replace('/\s+/', ' ', strtolower($value));
            }

            if ($valueOut !== null) {
                $allowed[] = $prop . ': ' . $valueOut;
            }
        }

        return implode('; ', $allowed);
    }
}

if (!function_exists('emsp_journal_is_safe_href')) {
    function emsp_journal_is_safe_href(string $href): bool
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return false;
        }
        if (preg_match('/^\s*(javascript:|data:)/i', $href)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('emsp_journal_is_safe_img_src')) {
    function emsp_journal_is_safe_img_src(string $src): bool
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '') {
            return false;
        }
        if (preg_match('/^\s*data:/i', $src)) {
            return strlen($src) <= (1024 * 100);
        }
        if (preg_match('#^https?://#i', $src)) {
            return true;
        }
        $src = ltrim(str_replace('\\', '/', $src), '/');
        return strpos($src, 'uploads/') === 0 || strpos($src, 'assets/') === 0;
    }
}

if (!function_exists('emsp_journal_clean_html')) {
    function emsp_journal_clean_html(string $html): string
    {
        $html = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($html) : $html;
        $decoded = htmlspecialchars_decode($html, ENT_QUOTES);
        $decoded = emsp_journal_fix_paths($decoded);

        if (!class_exists('DOMDocument')) {
            $allowed = '<p><br><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><em><b><i><u><a><img><blockquote><pre><code><table><thead><tbody><tr><th><td><div><span><figure><figcaption><hr><sup><sub><small><mark><del><ins>';
            return strip_tags($decoded, $allowed);
        }

        $allowedTags = [
            'p', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
            'strong', 'em', 'b', 'i', 'u', 'a', 'img', 'blockquote', 'pre', 'code',
            'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div', 'span', 'figure',
            'figcaption', 'hr', 'sup', 'sub', 'small', 'mark', 'del', 'ins'
        ];

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="utf-8" ?><div id="emsp-journal-root">' . $decoded . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('emsp-journal-root');
        if (!$root) {
            return '';
        }

        $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags): void {
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                $sanitizeNode($child);
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                return;
            }

            /** @var DOMElement $element */
            $element = $node;
            $tag = strtolower($element->tagName);
            if (!in_array($tag, $allowedTags, true)) {
                $parent = $element->parentNode;
                if ($parent) {
                    while ($element->firstChild) {
                        $parent->insertBefore($element->firstChild, $element);
                    }
                    $parent->removeChild($element);
                }
                return;
            }

            $allowedAttrs = ['style', 'class'];
            if ($tag === 'a') {
                $allowedAttrs = array_merge($allowedAttrs, ['href', 'target', 'rel', 'title']);
            } elseif ($tag === 'img') {
                $allowedAttrs = array_merge($allowedAttrs, ['src', 'alt', 'title', 'width', 'height']);
            }

            $attrs = [];
            foreach ($element->attributes as $attr) {
                $attrs[] = $attr->name;
            }

            foreach ($attrs as $attrName) {
                $value = $element->getAttribute($attrName);
                $lower = strtolower($attrName);
                if (!in_array($lower, $allowedAttrs, true)) {
                    $element->removeAttribute($attrName);
                    continue;
                }

                if ($lower === 'href') {
                    if (!emsp_journal_is_safe_href($value)) {
                        $element->removeAttribute($attrName);
                    } else {
                        $element->setAttribute('href', trim($value));
                        $element->setAttribute('rel', 'noopener noreferrer');
                    }
                    continue;
                }

                if ($lower === 'src') {
                    if (!emsp_journal_is_safe_img_src($value)) {
                        $parent = $element->parentNode;
                        if ($parent) {
                            $parent->removeChild($element);
                        }
                        return;
                    }
                    $element->setAttribute('src', emsp_journal_fix_paths(trim($value)));
                    continue;
                }

                if ($lower === 'class') {
                    $cleanClass = emsp_journal_normalize_class_list($value, $tag === 'img');
                    if ($cleanClass === '') {
                        $element->removeAttribute($attrName);
                    } else {
                        $element->setAttribute('class', $cleanClass);
                    }
                    continue;
                }

                if ($lower === 'style') {
                    $cleanStyle = emsp_journal_sanitize_style($value, $tag === 'img');
                    if ($cleanStyle === '') {
                        $element->removeAttribute($attrName);
                    } else {
                        $element->setAttribute('style', $cleanStyle);
                    }
                    continue;
                }

                if (in_array($lower, ['width', 'height'], true)) {
                    if (!preg_match('/^\d{1,4}$/', trim($value))) {
                        $element->removeAttribute($attrName);
                    } else {
                        $numeric = max(40, min(2000, (int) $value));
                        $element->setAttribute($attrName, (string) $numeric);
                    }
                }
            }
        };

        $sanitizeNode($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        return trim($output);
    }
}

if (!function_exists('emsp_journal_state')) {
    function emsp_journal_state(array $article, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $status = strtolower(trim((string) ($article['status'] ?? 'draft')));
        $startsAt = trim((string) ($article['starts_at'] ?? ''));
        $endsAt = trim((string) ($article['ends_at'] ?? ''));
        $closedAt = trim((string) ($article['closed_at'] ?? ''));

        $startDt = null;
        $endDt = null;
        $closedDt = null;
        try {
            if ($startsAt !== '') {
                $startDt = new DateTimeImmutable($startsAt);
            }
            if ($endsAt !== '') {
                $endDt = new DateTimeImmutable($endsAt);
            }
            if ($closedAt !== '') {
                $closedDt = new DateTimeImmutable($closedAt);
            }
        } catch (Exception $e) {
            $startDt = $startDt ?? null;
            $endDt = $endDt ?? null;
            $closedDt = $closedDt ?? null;
        }

        $code = 'draft';
        $label = 'Brouillon';
        if ($status === 'published') {
            $code = 'open';
            $label = 'Ouvert';
            if ($startDt && $now < $startDt) {
                $code = 'scheduled';
                $label = 'Planifie';
            } elseif ($closedDt) {
                $code = 'closed';
                $label = 'Clos';
            } elseif ($endDt && $now > $endDt) {
                $code = 'expired';
                $label = 'Expire';
            }
        }

        return [
            'code' => $code,
            'label' => $label,
            'is_open' => $code === 'open',
            'is_closed' => $code === 'closed',
            'is_expired' => $code === 'expired',
            'is_scheduled' => $code === 'scheduled',
            'starts_at_label' => emsp_journal_format_datetime($startsAt),
            'ends_at_label' => emsp_journal_format_datetime($endsAt),
            'closed_at_label' => emsp_journal_format_datetime($closedAt),
            'starts_at_iso' => $startsAt,
            'ends_at_iso' => $endsAt,
            'closed_at_iso' => $closedAt,
            'status' => $status,
        ];
    }
}

if (!function_exists('emsp_journal_fetch_public_summary')) {
    function emsp_journal_fetch_public_summary(mysqli $con, int $journalId, int $userId = 0): ?array
    {
        $stmt = mysqli_prepare($con, "SELECT * FROM journal WHERE id=? AND status='published' LIMIT 1");
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $journalId);
        mysqli_stmt_execute($stmt);
        $article = emsp_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);

        if (!$article) {
            return null;
        }

        $article['title'] = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($article['title'] ?? '')) : (string) ($article['title'] ?? '');
        $article['content'] = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($article['content'] ?? '')) : (string) ($article['content'] ?? '');
        $type = strtolower(trim((string) ($article['type'] ?? '')));
        $state = emsp_journal_state($article);

        $summary = [
            'id' => (int) ($article['id'] ?? 0),
            'title' => (string) ($article['title'] ?? ''),
            'content_html' => emsp_journal_clean_html((string) ($article['content'] ?? '')),
            'type' => $type,
            'type_meta' => emsp_journal_type_meta($type),
            'created_at' => (string) ($article['created_at'] ?? ''),
            'relative_date' => emsp_journal_relative_date((string) ($article['created_at'] ?? '')),
            'state' => $state,
            'owner_id' => (int) ($article['admin_id'] ?? $article['author_id'] ?? 0),
        ];

        $summary['like_count'] = 0;
        $summary['liked'] = false;
        $summary['comment_count'] = 0;
        $summary['comments'] = [];

        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS nb FROM journal_likes WHERE journal_id=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $journalId);
            mysqli_stmt_execute($stmt);
            $row = emsp_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);
            $summary['like_count'] = (int) ($row['nb'] ?? 0);
        }

        if ($userId > 0) {
            $stmt = mysqli_prepare($con, "SELECT 1 FROM journal_likes WHERE journal_id=? AND user_id=? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $journalId, $userId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $summary['liked'] = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);
            }
        }

        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS nb FROM journal_comments WHERE journal_id=? AND status='visible'");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $journalId);
            mysqli_stmt_execute($stmt);
            $row = emsp_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);
            $summary['comment_count'] = (int) ($row['nb'] ?? 0);
        }

        $stmt = mysqli_prepare(
            $con,
            "SELECT jc.id, jc.content, jc.created_at,
                    u.first_name, u.last_name, u.badge_level, u.photo_path
             FROM journal_comments jc
             INNER JOIN users u ON u.id = jc.user_id
             WHERE jc.journal_id=? AND jc.status='visible'
             ORDER BY jc.created_at ASC"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $journalId);
            mysqli_stmt_execute($stmt);
            $rows = emsp_stmt_fetch_all($stmt);
            mysqli_stmt_close($stmt);

            foreach ($rows as $row) {
                $firstName = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['first_name'] ?? '')) : (string) ($row['first_name'] ?? '');
                $lastName = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['last_name'] ?? '')) : (string) ($row['last_name'] ?? '');
                $content = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['content'] ?? '')) : (string) ($row['content'] ?? '');
                $summary['comments'][] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'content' => $content,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'relative_date' => emsp_journal_relative_date((string) ($row['created_at'] ?? '')),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'display_name' => trim($firstName . ' ' . $lastName),
                    'badge_level' => (string) ($row['badge_level'] ?? 'none'),
                    'photo_src' => function_exists('emsp_user_photo_src') ? emsp_user_photo_src((string) ($row['photo_path'] ?? '')) : '',
                    'initials' => function_exists('emsp_user_initials')
                        ? emsp_user_initials($firstName, $lastName)
                        : mb_strtoupper(mb_substr(trim($firstName . ' ' . $lastName), 0, 2, 'UTF-8'), 'UTF-8'),
                ];
            }
        }

        if ($type === 'sondage') {
            $pollOptions = [];
            $pollTotal = 0;
            $myOption = 0;
            $stmt = mysqli_prepare(
                $con,
                "SELECT jo.id, jo.label, COUNT(jv.id) AS votes
                 FROM journal_options jo
                 LEFT JOIN journal_votes jv ON jv.option_id = jo.id
                 WHERE jo.journal_id=?
                 GROUP BY jo.id, jo.label
                 ORDER BY jo.id"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $journalId);
                mysqli_stmt_execute($stmt);
                $rows = emsp_stmt_fetch_all($stmt);
                mysqli_stmt_close($stmt);
                foreach ($rows as $row) {
                    $votes = (int) ($row['votes'] ?? 0);
                    $pollOptions[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'label' => function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['label'] ?? '')) : (string) ($row['label'] ?? ''),
                        'votes' => $votes,
                    ];
                    $pollTotal += $votes;
                }
            }

            if ($userId > 0) {
                $stmt = mysqli_prepare($con, "SELECT option_id FROM journal_votes WHERE journal_id=? AND user_id=? LIMIT 1");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ii', $journalId, $userId);
                    mysqli_stmt_execute($stmt);
                    $row = emsp_stmt_fetch_assoc($stmt);
                    mysqli_stmt_close($stmt);
                    $myOption = (int) ($row['option_id'] ?? 0);
                }
            }

            $summary['poll'] = [
                'options' => $pollOptions,
                'total_votes' => $pollTotal,
                'my_option' => $myOption,
            ];
        } elseif ($type === 'defi') {
            $participantTotal = 0;
            $userDefi = false;
            $userNote = '';

            $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS nb FROM journal_defis WHERE journal_id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $journalId);
                mysqli_stmt_execute($stmt);
                $row = emsp_stmt_fetch_assoc($stmt);
                mysqli_stmt_close($stmt);
                $participantTotal = (int) ($row['nb'] ?? 0);
            }

            if ($userId > 0) {
                $stmt = mysqli_prepare($con, "SELECT note FROM journal_defis WHERE journal_id=? AND user_id=? LIMIT 1");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'ii', $journalId, $userId);
                    mysqli_stmt_execute($stmt);
                    $row = emsp_stmt_fetch_assoc($stmt);
                    mysqli_stmt_close($stmt);
                    if ($row) {
                        $userDefi = true;
                        $userNote = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['note'] ?? '')) : (string) ($row['note'] ?? '');
                    }
                }
            }

            $summary['defi'] = [
                'participant_total' => $participantTotal,
                'participated' => $userDefi,
                'note' => $userNote,
            ];
        }

        return $summary;
    }
}

if (!function_exists('emsp_journal_fetch_admin_details')) {
    function emsp_journal_fetch_admin_details(mysqli $con, int $journalId): ?array
    {
        $stmt = mysqli_prepare($con, "SELECT * FROM journal WHERE id=? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $journalId);
        mysqli_stmt_execute($stmt);
        $article = emsp_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);

        if (!$article) {
            return null;
        }

        $article['title'] = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($article['title'] ?? '')) : (string) ($article['title'] ?? '');
        $article['content'] = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($article['content'] ?? '')) : (string) ($article['content'] ?? '');
        $type = strtolower(trim((string) ($article['type'] ?? '')));

        $details = [
            'article' => $article,
            'state' => emsp_journal_state($article),
            'type_meta' => emsp_journal_type_meta($type),
            'type' => $type,
        ];

        if ($type === 'sondage') {
            $options = [];
            $totalVotes = 0;
            $stmt = mysqli_prepare(
                $con,
                "SELECT jo.id, jo.label, COUNT(jv.id) AS votes
                 FROM journal_options jo
                 LEFT JOIN journal_votes jv ON jv.option_id = jo.id
                 WHERE jo.journal_id=?
                 GROUP BY jo.id, jo.label
                 ORDER BY jo.id"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $journalId);
                mysqli_stmt_execute($stmt);
                $rows = emsp_stmt_fetch_all($stmt);
                mysqli_stmt_close($stmt);
                foreach ($rows as $row) {
                    $votes = (int) ($row['votes'] ?? 0);
                    $options[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'label' => function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['label'] ?? '')) : (string) ($row['label'] ?? ''),
                        'votes' => $votes,
                    ];
                    $totalVotes += $votes;
                }
            }
            $details['poll'] = [
                'options' => $options,
                'total_votes' => $totalVotes,
            ];
        } elseif ($type === 'defi') {
            $participants = [];
            $stmt = mysqli_prepare(
                $con,
                "SELECT jd.user_id, jd.note, jd.created_at, u.first_name, u.last_name, u.email
                 FROM journal_defis jd
                 INNER JOIN users u ON u.id = jd.user_id
                 WHERE jd.journal_id=?
                 ORDER BY jd.created_at DESC, jd.id DESC"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $journalId);
                mysqli_stmt_execute($stmt);
                $rows = emsp_stmt_fetch_all($stmt);
                mysqli_stmt_close($stmt);
                foreach ($rows as $row) {
                    $participants[] = [
                        'user_id' => (int) ($row['user_id'] ?? 0),
                        'name' => trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? ''))),
                        'email' => (string) ($row['email'] ?? ''),
                        'note' => function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) ($row['note'] ?? '')) : (string) ($row['note'] ?? ''),
                        'created_at' => (string) ($row['created_at'] ?? ''),
                        'created_at_label' => emsp_journal_format_datetime((string) ($row['created_at'] ?? '')),
                    ];
                }
            }
            $details['defi'] = [
                'participant_total' => count($participants),
                'participants' => $participants,
            ];
        }

        return $details;
    }
}



