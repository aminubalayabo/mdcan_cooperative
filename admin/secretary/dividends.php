<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole('secretary');

$pageTitle = 'Dividend Management';

// ── CSV download (final generation) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_csv'])) {
    $amount = (float)($_POST['appropriated_amount'] ?? 0);
    $year   = (int)($_POST['dividend_year'] ?? date('Y'));

    if ($amount <= 0) {
        flashMessage('danger', 'Invalid amount.');
        header('Location: ' . BASE_URL . '/admin/secretary/dividends.php');
        exit;
    }

    $totalSavings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings")->fetchColumn();
    if ($totalSavings <= 0) {
        flashMessage('danger', 'No savings on record.');
        header('Location: ' . BASE_URL . '/admin/secretary/dividends.php');
        exit;
    }

    $stmt = $pdo->query("SELECT m.mno, m.name, m.bank_name, m.account_number,
        COALESCE(SUM(s.amount),0) AS member_savings
        FROM members m
        LEFT JOIN savings s ON s.member_id = m.id
        WHERE m.status = 'active'
        GROUP BY m.id
        HAVING member_savings > 0
        ORDER BY m.mno ASC");
    $members = $stmt->fetchAll();

    if (empty($members)) {
        flashMessage('danger', 'No active members with savings found.');
        header('Location: ' . BASE_URL . '/admin/secretary/dividends.php');
        exit;
    }

    // Log the generation
    $pdo->prepare("INSERT INTO dividend_records (year,appropriated_amount,total_savings,members_count,generated_by) VALUES (?,?,?,?,?)")
        ->execute([$year, $amount, $totalSavings, count($members), $_SESSION['user_id']]);
    logAudit($pdo, $_SESSION['user_id'], 'admin', 'dividend_generated',
        "Year: $year, Appropriated: $amount, Total Savings: $totalSavings, Members: " . count($members));

    // Stream CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dividend_' . $year . '_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($out, ['S/N', 'MN No', 'Member Name', 'Savings (N)', 'Share %', 'Dividend (N)', 'Bank Name', 'Account Number']);
    $sn = 1;
    foreach ($members as $m) {
        $pct      = ($m['member_savings'] / $totalSavings) * 100;
        $dividend = ($m['member_savings'] / $totalSavings) * $amount;
        fputcsv($out, [
            $sn++,
            $m['mno'],
            $m['name'],
            number_format($m['member_savings'], 2),
            number_format($pct, 4) . '%',
            number_format($dividend, 2),
            $m['bank_name'],
            $m['account_number'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Preview calculation ───────────────────────────────────────────────────────
$previewAmount  = null;
$previewYear    = (int)date('Y') - 1; // default: last year
$previewMembers = [];
$totalSavings   = 0;
$totalDividend  = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_dividend'])) {
    $previewAmount = (float)($_POST['appropriated_amount'] ?? 0);
    $previewYear   = (int)($_POST['dividend_year'] ?? date('Y'));

    if ($previewAmount > 0) {
        $totalSavings = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM savings")->fetchColumn();

        $stmt = $pdo->query("SELECT m.id, m.mno, m.name, m.bank_name, m.account_number,
            COALESCE(SUM(s.amount),0) AS member_savings
            FROM members m
            LEFT JOIN savings s ON s.member_id = m.id
            WHERE m.status = 'active'
            GROUP BY m.id
            HAVING member_savings > 0
            ORDER BY m.mno ASC");
        $previewMembers = $stmt->fetchAll();

        foreach ($previewMembers as $m) {
            $totalDividend += ($m['member_savings'] / $totalSavings) * $previewAmount;
        }
    }
}

// ── Past records ──────────────────────────────────────────────────────────────
$pastRecords = $pdo->query("SELECT dr.*, a.name AS generated_by_name
    FROM dividend_records dr
    JOIN admins a ON dr.generated_by = a.id
    ORDER BY dr.generated_at DESC LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <!-- ── Left: Input Form ─────────────────────────────────────────────────── -->
    <div class="col-md-4">
        <div class="card card-success">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-percentage mr-2"></i>Generate Dividend</h3>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Each member's dividend is calculated as their proportion of total cooperative
                    savings multiplied by the appropriated dividend amount.
                </p>
                <form method="POST" id="previewForm">
                    <div class="form-group">
                        <label class="font-weight-bold">Dividend Year <span class="text-danger">*</span></label>
                        <input type="number" name="dividend_year" class="form-control"
                            value="<?= $previewYear ?>" min="2000" max="<?= date('Y') ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Appropriated Dividend Amount (&#8358;) <span class="text-danger">*</span></label>
                        <input type="number" name="appropriated_amount" id="appropriatedAmount" class="form-control"
                            min="1" step="any" value="<?= $previewAmount ? htmlspecialchars($previewAmount) : '' ?>"
                            placeholder="e.g. 500000" required>
                        <small class="text-muted">Total amount approved for distribution to members</small>
                    </div>
                    <button type="submit" name="preview_dividend" class="btn btn-success btn-block">
                        <i class="fas fa-eye mr-2"></i>Preview Dividend List
                    </button>
                </form>
            </div>
        </div>

        <!-- Past Records -->
        <?php if (!empty($pastRecords)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-history mr-2"></i>Past Dividend Records</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="thead-light">
                        <tr><th>Year</th><th>Amount</th><th>Members</th><th>Generated</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pastRecords as $r): ?>
                    <tr>
                        <td><?= $r['year'] ?></td>
                        <td><?= formatCurrency($r['appropriated_amount']) ?></td>
                        <td><?= $r['members_count'] ?></td>
                        <td>
                            <small><?= date('M d, Y', strtotime($r['generated_at'])) ?></small><br>
                            <small class="text-muted">by <?= sanitize($r['generated_by_name']) ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Right: Preview Table ─────────────────────────────────────────────── -->
    <div class="col-md-8">
        <?php if ($previewAmount !== null && $previewAmount > 0 && !empty($previewMembers)): ?>
        <div class="card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-list-ol mr-2"></i>
                    Dividend Preview — <?= $previewYear ?> &nbsp;
                    <small>(<?= count($previewMembers) ?> members &bull;
                    Appropriated: <?= formatCurrency($previewAmount) ?>)</small>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:500px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-dark" style="position:sticky;top:0">
                        <tr>
                            <th>#</th>
                            <th>MN No</th>
                            <th>Member Name</th>
                            <th>Savings (Share)</th>
                            <th>Share %</th>
                            <th>Dividend</th>
                            <th>Bank / Account</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewMembers as $i => $m):
                        $pct      = $totalSavings > 0 ? ($m['member_savings'] / $totalSavings) * 100 : 0;
                        $dividend = $totalSavings > 0 ? ($m['member_savings'] / $totalSavings) * $previewAmount : 0;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($m['mno']) ?></strong></td>
                        <td><?= sanitize($m['name']) ?></td>
                        <td><?= formatCurrency($m['member_savings']) ?></td>
                        <td>
                            <span class="badge badge-info"><?= number_format($pct, 3) ?>%</span>
                        </td>
                        <td class="font-weight-bold text-success"><?= formatCurrency($dividend) ?></td>
                        <td>
                            <small><?= sanitize($m['bank_name']) ?></small><br>
                            <small class="text-muted"><?= sanitize($m['account_number']) ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light font-weight-bold">
                        <tr>
                            <td colspan="3" class="text-right">Totals:</td>
                            <td><?= formatCurrency($totalSavings) ?></td>
                            <td>100%</td>
                            <td class="text-success"><?= formatCurrency($totalDividend) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                </div>
            </div>
            <div class="card-footer text-right">
                <form method="POST" id="csvForm">
                    <input type="hidden" name="appropriated_amount" value="<?= htmlspecialchars($previewAmount) ?>">
                    <input type="hidden" name="dividend_year" value="<?= $previewYear ?>">
                    <button type="button" class="btn btn-primary btn-lg"
                        onclick="confirmAndDownload()">
                        <i class="fas fa-file-csv mr-2"></i>Confirm &amp; Download CSV
                    </button>
                </form>
            </div>
        </div>

        <?php elseif ($previewAmount !== null && $previewAmount > 0 && empty($previewMembers)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            No active members with savings found. Please ensure savings have been recorded before generating dividends.
        </div>

        <?php else: ?>
        <div class="card card-body text-center text-muted py-5">
            <i class="fas fa-percentage fa-4x mb-3 text-success"></i>
            <h5>Enter an appropriated amount on the left and click <strong>Preview Dividend List</strong></h5>
            <p class="mb-0 small">A breakdown of each member's dividend will appear here before you download.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmAndDownload() {
    var amount = document.getElementById('appropriatedAmount')
                     ? document.getElementById('appropriatedAmount').value
                     : '<?= $previewAmount ?>';
    var formatted = parseFloat('<?= $previewAmount ?>').toLocaleString('en-NG', {
        style: 'currency', currency: 'NGN', minimumFractionDigits: 2
    });
    var msg = 'Are you sure you want to generate dividend for ' + formatted + '?\n\n' +
              'This action will be logged. The CSV will be downloaded immediately.';
    if (confirm(msg)) {
        document.getElementById('csvForm').appendChild(
            Object.assign(document.createElement('input'),
                {type:'hidden', name:'download_csv', value:'1'})
        );
        document.getElementById('csvForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
