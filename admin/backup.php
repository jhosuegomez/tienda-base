<?php
declare(strict_types=1);

// Database backup/export (route admin/backup, store_admin only).
// Pure-PHP SQL dump (no exec/mysqldump): schema via SHOW CREATE TABLE plus
// chunked row INSERTs. GET renders a confirm button; POST streams the file
// download and exits before the layout renders.
Auth::require_role('store_admin');
$title = 'Respaldo de la base de datos';

$errors = [];

// Fixed table allowlist (never trust client input for identifiers).
$backupTables = [
    'users', 'login_attempts', 'categories', 'products', 'product_images',
    'carts', 'cart_items', 'orders', 'order_items', 'settings',
    'bank_accounts', 'payment_receipts', 'jobs', 'audit_log',
];

function backup_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Sesión inválida. Recargá la página e intentá de nuevo.';
    } elseif ((string) ($_POST['section'] ?? '') !== 'backup_run') {
        $errors[] = 'Sección desconocida.';
    } else {
        try {
            $pdo = Database::pdo();
            $me = Auth::user();
            Audit::log($pdo, $me !== null ? (int) $me['id'] : null, 'backup.export', 'database', 0, 'manual');
            if (function_exists('set_time_limit')) {
                try {
                    set_time_limit(0);
                } catch (Throwable $e) {
                    // Shared hostings may forbid it; chunking still bounds memory.
                }
            }
            // Discard the layout buffer: the download must be raw SQL.
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $fileName = 'respaldo-' . date('Ymd-His') . '.sql';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('X-Content-Type-Options: nosniff');
            echo '-- Tienda base backup (' . date('Y-m-d H:i:s') . ")\n";
            echo "SET NAMES utf8mb4;\n\n";
            foreach ($backupTables as $table) {
                $ident = backup_ident($table);
                $create = $pdo->query('SHOW CREATE TABLE ' . $ident);
                if ($create === false) {
                    continue;
                }
                $createRow = $create->fetch(PDO::FETCH_ASSOC);
                if (!is_array($createRow)) {
                    continue;
                }
                $ddl = (string) ($createRow['Create Table'] ?? '');
                if ($ddl === '') {
                    continue;
                }
                echo 'DROP TABLE IF EXISTS ' . $ident . ";\n";
                echo $ddl . ";\n\n";
                $cnt = $pdo->query('SELECT COUNT(*) AS n FROM ' . $ident);
                $cntRow = ($cnt !== false) ? $cnt->fetch(PDO::FETCH_ASSOC) : null;
                $total = (is_array($cntRow)) ? (int) ($cntRow['n'] ?? 0) : 0;
                $chunk = 500;
                $cols = [];
                for ($offset = 0; $offset < $total; $offset += $chunk) {
                    $stmt = $pdo->prepare(
                        'SELECT * FROM ' . $ident . ' LIMIT :lim OFFSET :off'
                    );
                    $stmt->bindValue(':lim', $chunk, PDO::PARAM_INT);
                    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!is_array($rows) || $rows === []) {
                        break;
                    }
                    if ($cols === []) {
                        $first = reset($rows);
                        if (is_array($first)) {
                            $cols = array_keys($first);
                        }
                    }
                    if ($cols === []) {
                        break;
                    }
                    $colList = implode(',', array_map('backup_ident', $cols));
                    $values = [];
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $cells = [];
                        foreach ($cols as $col) {
                            $val = $row[$col] ?? null;
                            if ($val === null) {
                                $cells[] = 'NULL';
                            } else {
                                $quoted = $pdo->quote((string) $val);
                                $cells[] = ($quoted === false) ? "''" : $quoted;
                            }
                        }
                        $values[] = '(' . implode(',', $cells) . ')';
                    }
                    if ($values !== []) {
                        echo 'INSERT INTO ' . $ident . ' (' . $colList . ') VALUES' . "\n"
                            . implode(",\n", $values) . ";\n";
                    }
                    if (function_exists('flush')) {
                        flush();
                    }
                }
                echo "\n";
            }
            exit;
        } catch (Throwable $e) {
            if (headers_sent()) {
                exit;
            }
            $errors[] = 'No pudimos generar el respaldo. Intentá de nuevo más tarde.';
        }
    }
}

$csrf = csrf_token();
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-2xl font-extrabold text-slate-900">Respaldo de la base de datos</h1>
</div>

  <?php foreach ($errors as $msg): ?>
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo esc($msg); ?></div>
  <?php endforeach; ?>

  <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-bold text-slate-900">Descargar respaldo (.sql)</h2>
    <p class="mt-1 text-sm text-slate-500">Genera un archivo SQL con el esquema y los datos (por tandas de 500 filas para no agotar la memoria). Guardalo en un lugar seguro.</p>
    <form class="mt-3" method="post" action="index.php?r=admin/backup">
      <input type="hidden" name="csrf" value="<?php echo esc($csrf); ?>">
      <input type="hidden" name="section" value="backup_run">
      <button class="rounded-lg bg-[var(--primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" type="submit">Descargar respaldo</button>
    </form>
  </div>
