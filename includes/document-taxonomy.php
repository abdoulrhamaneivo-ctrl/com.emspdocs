<?php

if (!function_exists('emsp_schema_table_exists')) {
    function emsp_schema_table_exists(mysqli $con, string $table): bool
    {
        static $cache = [];
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safeTable === '') {
            return $cache[$table] = false;
        }

        $result = @mysqli_query($con, "SHOW TABLES LIKE '" . mysqli_real_escape_string($con, $safeTable) . "'");
        $cache[$table] = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }

        return $cache[$table];
    }
}

if (!function_exists('emsp_schema_column_exists')) {
    function emsp_schema_column_exists(mysqli $con, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return $cache[$key] = false;
        }

        $result = @mysqli_query($con, "SHOW COLUMNS FROM `{$safeTable}` LIKE '" . mysqli_real_escape_string($con, $safeColumn) . "'");
        $cache[$key] = $result instanceof mysqli_result && mysqli_num_rows($result) > 0;
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }

        return $cache[$key];
    }
}

if (!function_exists('emsp_schema_column_nullable')) {
    function emsp_schema_column_nullable(mysqli $con, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($safeTable === '' || $safeColumn === '') {
            return $cache[$key] = false;
        }

        $isNullable = 'NO';
        $result = @mysqli_query($con, "SHOW COLUMNS FROM `{$safeTable}` LIKE '" . mysqli_real_escape_string($con, $safeColumn) . "'");
        if ($result instanceof mysqli_result) {
            $row = mysqli_fetch_assoc($result);
            if (is_array($row) && isset($row['Null'])) {
                $isNullable = (string) $row['Null'];
            }
            mysqli_free_result($result);
        }

        return $cache[$key] = (strtoupper((string) $isNullable) === 'YES');
    }
}

if (!function_exists('emsp_document_filieres_enabled')) {
    function emsp_document_filieres_enabled(mysqli $con): bool
    {
        return emsp_schema_table_exists($con, 'document_filieres');
    }
}

if (!function_exists('emsp_pending_matiere_enabled')) {
    function emsp_pending_matiere_enabled(mysqli $con): bool
    {
        return emsp_schema_column_exists($con, 'documents', 'matiere_label_pending');
    }
}

if (!function_exists('emsp_collect_int_ids')) {
    function emsp_collect_int_ids($raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}

if (!function_exists('emsp_normalize_taxonomy_label')) {
    function emsp_normalize_taxonomy_label(string $value): string
    {
        $value = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($value) : $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') {
            return '';
        }

        $ascii = $value;
        if (class_exists('Normalizer')) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_D);
            if (is_string($normalized) && $normalized !== '') {
                $ascii = preg_replace('/\p{Mn}+/u', '', $normalized) ?? $normalized;
            }
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $ascii = $converted;
            }
        }

        $ascii = strtolower($ascii);
        $ascii = trim(preg_replace('/\s+/u', ' ', $ascii) ?? $ascii);

        return $ascii;
    }
}

if (!function_exists('emsp_fetch_document_filieres_map')) {
    function emsp_fetch_document_filieres_map(mysqli $con, array $documentIds): array
    {
        $documentIds = emsp_collect_int_ids($documentIds);
        if (empty($documentIds)) {
            return [];
        }

        $map = [];
        $idsSql = implode(',', array_map('intval', $documentIds));

        if (emsp_document_filieres_enabled($con)) {
            $sql = "SELECT df.document_id, f.id AS filiere_id, f.name
                    FROM document_filieres df
                    JOIN filieres f ON f.id = df.filiere_id
                    WHERE df.document_id IN ($idsSql)
                    ORDER BY f.name";
        } else {
            $sql = "SELECT d.id AS document_id, f.id AS filiere_id, f.name
                    FROM documents d
                    LEFT JOIN filieres f ON f.id = d.filiere_id
                    WHERE d.id IN ($idsSql)
                    ORDER BY f.name";
        }

        $result = mysqli_query($con, $sql);
        if (!$result) {
            return $map;
        }

        while ($row = mysqli_fetch_assoc($result)) {
            $docId = (int) ($row['document_id'] ?? 0);
            $filiereId = (int) ($row['filiere_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($docId <= 0 || $filiereId <= 0 || $name === '') {
                continue;
            }
            $map[$docId][] = [
                'id' => $filiereId,
                'name' => function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($name) : $name,
            ];
        }

        return $map;
    }
}

if (!function_exists('emsp_fetch_document_filiere_labels')) {
    function emsp_fetch_document_filiere_labels(mysqli $con, array $documentIds): array
    {
        $map = emsp_fetch_document_filieres_map($con, $documentIds);
        $labels = [];

        foreach ($map as $docId => $rows) {
            $names = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
            $labels[(int) $docId] = array_values($names);
        }

        return $labels;
    }
}

if (!function_exists('emsp_sync_document_filieres')) {
    function emsp_sync_document_filieres(mysqli $con, int $documentId, array $filiereIds): void
    {
        $documentId = (int) $documentId;
        if ($documentId <= 0) {
            return;
        }

        $filiereIds = emsp_collect_int_ids($filiereIds);
        $legacyId = $filiereIds[0] ?? null;

        if ($legacyId !== null) {
            $stmt = mysqli_prepare($con, "UPDATE documents SET filiere_id=? WHERE id=?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ii', $legacyId, $documentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
        } else {
            mysqli_query($con, "UPDATE documents SET filiere_id=NULL WHERE id=" . $documentId . " LIMIT 1");
        }

        if (!emsp_document_filieres_enabled($con)) {
            return;
        }

        $delete = mysqli_prepare($con, "DELETE FROM document_filieres WHERE document_id=?");
        if ($delete) {
            mysqli_stmt_bind_param($delete, 'i', $documentId);
            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);
        }

        if (empty($filiereIds)) {
            return;
        }

        $insert = mysqli_prepare(
            $con,
            "INSERT INTO document_filieres (document_id, filiere_id) VALUES (?, ?)"
        );
        if (!$insert) {
            return;
        }

        foreach ($filiereIds as $filiereId) {
            mysqli_stmt_bind_param($insert, 'ii', $documentId, $filiereId);
            mysqli_stmt_execute($insert);
        }
        mysqli_stmt_close($insert);
    }
}

if (!function_exists('emsp_find_existing_matiere_id')) {
    function emsp_find_existing_matiere_id(mysqli $con, string $label): int
    {
        $needle = emsp_normalize_taxonomy_label($label);
        if ($needle === '') {
            return 0;
        }

        $rows = mysqli_query($con, "SELECT id, name FROM matieres WHERE status='active' ORDER BY name");
        if (!$rows) {
            return 0;
        }

        while ($row = mysqli_fetch_assoc($rows)) {
            $name = (string) ($row['name'] ?? '');
            if (emsp_normalize_taxonomy_label($name) === $needle) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }
}

if (!function_exists('emsp_resolve_matiere_id')) {
    function emsp_resolve_matiere_id(
        mysqli $con,
        ?int $selectedId,
        string $labelToCreate,
        ?string &$errorMessage = null
    ): int {
        $selectedId = (int) ($selectedId ?? 0);
        if ($selectedId > 0) {
            $stmt = mysqli_prepare($con, "SELECT id FROM matieres WHERE id=? LIMIT 1");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $selectedId);
                mysqli_stmt_execute($stmt);
                $row = emsp_stmt_fetch_assoc($stmt);
                mysqli_stmt_close($stmt);
                if ($row) {
                    return $selectedId;
                }
            }
        }

        $labelToCreate = trim(preg_replace('/\s+/u', ' ', $labelToCreate) ?? $labelToCreate);
        if ($labelToCreate === '') {
            return 0;
        }

        $existingId = emsp_find_existing_matiere_id($con, $labelToCreate);
        if ($existingId > 0) {
            return $existingId;
        }

        if (emsp_schema_column_exists($con, 'matieres', 'module_id')
            && !emsp_schema_column_nullable($con, 'matieres', 'module_id')) {
            $errorMessage = "La migration SQL 'matiÃ¨res sans module obligatoire' n'a pas encore Ã©tÃ© appliquÃ©e.";
            return 0;
        }

        if (emsp_schema_column_exists($con, 'matieres', 'module_id')) {
            $stmt = mysqli_prepare(
                $con,
                "INSERT INTO matieres (name, module_id, status) VALUES (?, NULL, 'active')"
            );
        } else {
            $stmt = mysqli_prepare(
                $con,
                "INSERT INTO matieres (name, status) VALUES (?, 'active')"
            );
        }

        if (!$stmt) {
            $errorMessage = 'Impossible de prÃ©parer la crÃ©ation de la matiÃ¨re.';
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 's', $labelToCreate);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $errorMessage = 'Impossible de crÃ©er la matiÃ¨re validÃ©e.';
            return 0;
        }

        $newId = (int) mysqli_insert_id($con);
        mysqli_stmt_close($stmt);

        return $newId;
    }
}


