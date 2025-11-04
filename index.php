<?php
// index.php

/******************************
 * KONFIGURASI DATABASE
 ******************************/
$DB_DRIVER  = 'mysql';
$DB_HOST    = 'sql313.infinityfree.com';
$DB_PORT    = '3306';
$DB_NAME    = 'if0_40291120_northwind';
$DB_USER    = 'if0_40291120';
$DB_PASS    = 'Semarangkota12';
$CHARSET    = 'utf8mb4';

/******************************
 * KONEKSI PDO
 ******************************/
$dsn = "{$DB_DRIVER}:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset={$CHARSET}";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$CHARSET}"
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Database connection failed</h1>";
    echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

/******************************
 * PENGATURAN UI
 ******************************/
$allowedTables = [
    'customers' => 'Customers',
    'employees' => 'Employees',
    'invoices'  => 'Invoices',
    'orders'    => 'Orders',
    'products'  => 'Products',
    'shippers'  => 'Shippers',
    'suppliers' => 'Suppliers',
];

// Section default yang dibuka pertama kali:
$defaultSection = 'customers';

// Batas baris per halaman per tabel (bisa override via ?limit_customers=50, dst)
$defaultLimit = 20;

/******************************
 * UTILITAS
 ******************************/
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function getColumns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare("DESCRIBE `$table`");
    $stmt->execute();
    $cols = [];
    foreach ($stmt as $row) {
        $cols[] = $row['Field'];
    }
    return $cols;
}

function getTotalCount(PDO $pdo, string $table): int {
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `$table`");
    return (int)$stmt->fetch()['c'];
}

function fetchRows(PDO $pdo, string $table, int $limit, int $offset): array {
    // Urutkan default by primary key kalau ada kolom id, kalau tidak ORDER BY 1
    $orderBy = '1';
    $cols = getColumns($pdo, $table);
    if (in_array('id', $cols, true)) {
        $orderBy = '`id`';
    }
    $sql = "SELECT * FROM `$table` ORDER BY $orderBy LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function renderPagination(string $tableKey, int $total, int $limit, int $page): string {
    $pages = max(1, (int)ceil($total / max(1, $limit)));
    if ($pages <= 1) return '';

    $query = $_GET;
    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';

    $prev = max(1, $page - 1);
    $next = min($pages, $page + 1);

    $query["page_$tableKey"] = $prev;
    $html .= '<li class="page-item'.($page<=1?' disabled':'').'"><a class="page-link" href="?'.h(http_build_query($query)).'">&laquo;</a></li>';

    // tampilkan sedikit halaman sekitar current
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    for ($p = $start; $p <= $end; $p++) {
        $query["page_$tableKey"] = $p;
        $active = $p === $page ? ' active' : '';
        $html .= '<li class="page-item'.$active.'"><a class="page-link" href="?'.h(http_build_query($query)).'">'.h($p).'</a></li>';
    }

    $query["page_$tableKey"] = $next;
    $html .= '<li class="page-item'.($page>=$pages?' disabled':'').'"><a class="page-link" href="?'.h(http_build_query($query)).'">&raquo;</a></li>';

    $html .= '</ul></nav>';
    return $html;
}

/******************************
 * PERSIAPAN DATA PER TABEL
 ******************************/
$tablesData = [];
foreach ($allowedTables as $key => $label) {
    // limit & page khusus per tabel via query string: limit_customers, page_customers, dst.
    $limitParam = "limit_{$key}";
    $pageParam  = "page_{$key}";

    $limit = isset($_GET[$limitParam]) ? max(1, min(200, (int)$_GET[$limitParam])) : $defaultLimit;
    $page  = isset($_GET[$pageParam])  ? max(1, (int)$_GET[$pageParam]) : 1;
    $offset = ($page - 1) * $limit;

    // Ambil kolom dan data
    $columns = getColumns($pdo, $key);
    $total   = getTotalCount($pdo, $key);
    $rows    = fetchRows($pdo, $key, $limit, $offset);

    $tablesData[$key] = [
        'label'   => $label,
        'columns' => $columns,
        'rows'    => $rows,
        'total'   => $total,
        'limit'   => $limit,
        'page'    => $page,
    ];
}

// Section aktif awal (bisa via ?show=orders, dll.)
$active = isset($_GET['show']) && isset($allowedTables[$_GET['show']])
    ? $_GET['show']
    : $defaultSection;

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Northwind – Data Browser</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f7f7fb; }
        .navbar-brand { font-weight: 700; letter-spacing: .3px; }
        .card { border-radius: 1rem; box-shadow: 0 6px 18px rgba(0,0,0,.06); }
        .table thead th { white-space: nowrap; }
        .sticky-header { position: sticky; top: 0; background: #fff; z-index: 1; }
        .section-collapse { transition: height .25s ease; }
        .dropdown-menu .dropdown-item.active, .dropdown-menu .dropdown-item:active { background-color: #0d6efd; }
        .limit-input { width: 80px; }
        .table-responsive { max-height: 62vh; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">Northwind Browser</a>
    <div class="dropdown">
      <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
        Pilih Tabel
      </button>
      <ul class="dropdown-menu">
        <?php foreach ($allowedTables as $key => $label): ?>
          <li>
            <a class="dropdown-item<?= $active === $key ? ' active' : '' ?>"
               href="#"
               data-target="#section-<?= h($key) ?>">
               <?= h($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</nav>

<div class="container my-4">

  <?php foreach ($tablesData as $key => $data): ?>
    <?php
      $isActive = ($key === $active);
      $secId = "section-{$key}";
      $label = $data['label'];
      $limitParam = "limit_{$key}";
      $pageParam  = "page_{$key}";
      $limit = $data['limit'];
      $page  = $data['page'];
      $total = $data['total'];
      $columns = $data['columns'];
      $rows    = $data['rows'];
    ?>
    <div class="collapse section-collapse <?= $isActive ? 'show' : '' ?>" id="<?= h($secId) ?>">
      <div class="card mb-4">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
          <div>
            <h5 class="mb-0"><?= h($label) ?></h5>
            <small class="text-muted">Total baris: <?= h(number_format($total)) ?></small>
          </div>
          <div class="ms-auto d-flex align-items-center gap-2">
            <form class="d-flex align-items-center" method="get">
              <?php
                // pertahankan show agar tetap di section sama
                $qs = $_GET;
                $qs['show'] = $key;
                // Reset halaman ke 1 saat ubah limit
                $qs["page_$key"] = 1;
              ?>
              <?php foreach ($qs as $k => $v): if ($k !== $limitParam) : ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
              <?php endif; endforeach; ?>

              <label class="me-2">Limit</label>
              <input class="form-control form-control-sm limit-input" type="number" min="1" max="200" name="<?= h($limitParam) ?>" value="<?= h($limit) ?>">
              <button class="btn btn-sm btn-outline-primary ms-2" type="submit">Terapkan</button>
            </form>
          </div>
        </div>

        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-striped table-hover mb-0">
              <thead class="sticky-header">
                <tr>
                  <?php foreach ($columns as $col): ?>
                    <th scope="col"><?= h($col) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php if (!$rows): ?>
                  <tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">Tidak ada data untuk halaman ini.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <?php foreach ($columns as $col): ?>
                        <td><?= h($r[$col] ?? '') ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card-footer d-flex justify-content-between align-items-center">
          <small class="text-muted">
            Halaman <?= h($page) ?> dari <?= h(max(1, (int)ceil($total / max(1,$limit)))) ?>
          </small>
          <div>
            <?= renderPagination($key, $total, $limit, $page); ?>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

</div>

<footer class="text-center text-muted pb-4">
  <small>&copy; <?= date('Y') ?> Northwind Browser</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Kendalikan dropdown -> buka/utup section collapse yang sesuai, tutup yang lain
document.querySelectorAll('.dropdown-item[data-target]').forEach(function(item) {
  item.addEventListener('click', function (e) {
    e.preventDefault();
    const target = item.getAttribute('data-target');
    const targetEl = document.querySelector(target);
    if (!targetEl) return;

    // Tutup semua section dulu
    document.querySelectorAll('.section-collapse.show').forEach(function(opened) {
      if (opened !== targetEl) {
        const c = bootstrap.Collapse.getOrCreateInstance(opened);
        c.hide();
      }
    });

    // Buka yang dipilih
    const collapse = bootstrap.Collapse.getOrCreateInstance(targetEl, { toggle: false });
    collapse.show();

    // Tandai item aktif
    document.querySelectorAll('.dropdown-item').forEach(el => el.classList.remove('active'));
    item.classList.add('active');

    // Update URL param 'show' agar bisa di-refresh tetap di section sama
    const url = new URL(window.location.href);
    url.searchParams.set('show', target.replace('#section-', ''));
    history.replaceState({}, '', url.toString());
  });
});

// Saat halaman load pertama kali, pastikan section aktif sesuai ?show
(function syncActiveMenu() {
  const url = new URL(window.location.href);
  const show = url.searchParams.get('show') || '<?= h($active) ?>';
  const toActivate = document.querySelector('.dropdown-item[data-target="#section-' + show + '"]');
  if (toActivate) {
    document.querySelectorAll('.dropdown-item').forEach(el => el.classList.remove('active'));
    toActivate.classList.add('active');
  }
})();
</script>

</body>
</html>
