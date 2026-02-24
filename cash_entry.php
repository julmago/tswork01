<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cash_helpers.php';
require_login();

$cashbox_id = (int)($_SESSION['cashbox_id'] ?? 0);
$cashbox_name = '—';
if ($cashbox_id > 0) {
  $name_st = db()->prepare("SELECT name FROM cashboxes WHERE id = ? LIMIT 1");
  $name_st->execute([$cashbox_id]);
  $cashbox_name = $name_st->fetchColumn() ?: '—';
}

$cashbox = null;
if ($cashbox_id > 0) {
  $cashbox = require_cashbox_selected();
  require_permission(hasCashboxPerm('can_create_entries', (int)$cashbox['id']), 'Sin permiso para crear entradas.');
}
$user = current_user();

$message = '';
$error = '';

$denominations = [];
if ($cashbox) {
  $denom_st = db()->prepare("SELECT id, value FROM cash_denominations WHERE cashbox_id = ? AND is_active = 1 ORDER BY sort_order ASC, value ASC");
  $denom_st->execute([(int)$cashbox['id']]);
  $denominations = $denom_st->fetchAll();
}

if (is_post() && post('action') === 'create_entry') {
  if (!$cashbox) {
    $error = 'Seleccioná una caja activa para continuar.';
  }
  $detail = trim((string)post('detail'));
  $amount_raw = trim((string)post('amount'));
  $amount_raw = str_replace([' ', ','], ['', '.'], $amount_raw);
  $amount = (float)$amount_raw;

  if ($error !== '') {
    // no-op, keep error
  } elseif ($detail === '') {
    $error = 'El detalle es obligatorio.';
  } elseif ($amount <= 0) {
    $error = 'El efectivo debe ser mayor a 0.';
  } else {
    $st = db()->prepare("INSERT INTO cash_movements (cashbox_id, type, detail, amount, user_id) VALUES (?, 'entry', ?, ?, ?)");
    $st->execute([(int)$cashbox['id'], $detail, $amount, (int)$user['id']]);
    $message = 'Entrada registrada correctamente.';
  }
}

$responsible_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if ($responsible_name === '') {
  $responsible_name = $user['email'] ?? 'Usuario';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>TS WORK</title>
  <?= theme_css_links() ?>
</head>
<body class="app-body">
<?php require __DIR__ . '/partials/header.php'; ?>

<main class="page">
  <div class="container">
    <div class="page-header">
      <h2 class="page-title">Entrada de caja</h2>
      <span class="muted">Caja activa: <?= e($cashbox_name) ?></span>
    </div>

    <?php if ($message): ?><div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$cashbox): ?>
      <div class="alert alert-warning">Seleccioná una caja activa para registrar movimientos.</div>
      <div class="form-actions">
        <a class="btn" href="<?= url_path('cash_select.php') ?>">Elegir caja</a>
      </div>
    <?php else: ?>
      <div class="cash-layout">
        <div class="card cash-form">
          <div class="card-header">
            <h3 class="card-title">Nueva entrada</h3>
          </div>
          <form method="post" class="stack">
            <input type="hidden" name="action" value="create_entry">
            <div class="form-group">
              <label class="form-label">Detalle</label>
              <input class="form-control" type="text" name="detail" required maxlength="255">
            </div>
            <div class="form-group">
              <label class="form-label">Efectivo</label>
              <input class="form-control" type="number" name="amount" step="0.01" min="0" required>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Responsable</label>
                <input class="form-control" type="text" value="<?= e($responsible_name) ?>" readonly>
              </div>
              <div class="form-group">
                <label class="form-label">Fecha y hora</label>
                <input class="form-control" type="text" value="<?= e(date('d/m/Y H:i')) ?>" readonly>
              </div>
            </div>
            <div class="form-actions">
              <button class="btn" type="submit">Registrar entrada</button>
              <a class="btn btn-ghost" href="<?= url_path('cash_select.php') ?>">Volver</a>
            </div>
          </form>
        </div>

        <div class="card cash-bills">
          <div class="card-header">
            <h3 class="card-title">Contador de billetes</h3>
          </div>
          <?php if ($denominations): ?>
            <div class="cash-bills-content">
              <div class="denom-list">
                <?php foreach ($denominations as $denom): ?>
                  <div class="denom-row">
                    <span>$<?= number_format((int)$denom['value'], 0, ',', '.') ?></span>
                    <input class="form-control" type="number" min="0" value="" placeholder="0" data-denom-value="<?= (int)$denom['value'] ?>">
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="denom-total">
                <span>Total</span>
                <span data-denom-total>0</span>
              </div>
            </div>
            <div class="cash-card-actions">
              <button class="btn btn-ghost btn-block denom-copy" type="button" data-denom-copy>Usar total como efectivo</button>
            </div>
          <?php else: ?>
            <div class="alert alert-info">No hay billetes configurados para esta caja.</div>
          <?php endif; ?>
        </div>

        <div class="card cash-calculator">
          <div class="card-header">
            <h3 class="card-title">Calculadora</h3>
          </div>
          <div class="cash-calculator-content">
            <input class="calculator-display" type="text" data-calculator-display value="0" readonly>
            <div class="calculator-grid" data-calculator>
              <button type="button" data-value="7">7</button>
              <button type="button" data-value="8">8</button>
              <button type="button" data-value="9">9</button>
              <button type="button" data-value="/">/</button>
              <button type="button" data-value="4">4</button>
              <button type="button" data-value="5">5</button>
              <button type="button" data-value="6">6</button>
              <button type="button" data-value="*">*</button>
              <button type="button" data-value="1">1</button>
              <button type="button" data-value="2">2</button>
              <button type="button" data-value="3">3</button>
              <button type="button" data-value="-">-</button>
              <button type="button" data-value="0">0</button>
              <button type="button" data-value=".">.</button>
              <button type="button" data-action="back">⌫</button>
              <button type="button" data-value="+">+</button>
              <button type="button" data-action="clear">C</button>
              <button type="button" data-action="vat-plus">+ IVA</button>
              <button type="button" data-action="vat-minus">- IVA</button>
              <button type="button" data-action="equals">=</button>
            </div>
          </div>
          <div class="cash-card-actions">
            <button class="btn btn-ghost btn-block calculator-copy" type="button" data-calculator-copy>
              Usar total como efectivo
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<script>
  (() => {
    const denomInputs = document.querySelectorAll('[data-denom-value]');
    const totalEl = document.querySelector('[data-denom-total]');
    const copyButton = document.querySelector('[data-denom-copy]');
    const amountInput = document.querySelector('input[name="amount"]');

    const updateTotal = () => {
      let total = 0;
      denomInputs.forEach((input) => {
        const value = parseInt(input.dataset.denomValue || '0', 10);
        const qty = parseInt(input.value || '0', 10);
        if (!Number.isNaN(value) && !Number.isNaN(qty)) {
          total += value * qty;
        }
      });
      if (totalEl) {
        totalEl.textContent = total.toLocaleString('es-AR');
      }
      return total;
    };

    denomInputs.forEach((input) => {
      input.addEventListener('input', updateTotal);
    });

    copyButton?.addEventListener('click', () => {
      const total = updateTotal();
      if (amountInput) {
        amountInput.value = (total / 1).toFixed(2);
      }
    });

    const display = document.querySelector('[data-calculator-display]');
    const keypad = document.querySelector('[data-calculator]');
    const calculatorCard = document.querySelector('.cash-calculator');
    const calculatorCopyButton = document.querySelector('[data-calculator-copy]');
    let expression = '0';
    let calculatorActive = false;

    const setDisplay = (value) => {
      expression = value;
      if (display) {
        display.value = value;
      }
    };

    const evaluateExpression = () => {
      const safe = expression.replace(',', '.');
      if (!/^[0-9+\-*/().\s]+$/.test(safe)) {
        return null;
      }
      try {
        // eslint-disable-next-line no-new-func
        const result = Function(`return (${safe})`)();
        if (typeof result === 'number' && Number.isFinite(result)) {
          return result;
        }
      } catch (_e) {
        return null;
      }
      return null;
    };

    const applyCalculatorInput = ({ value, action }) => {
      if (action === 'clear') {
        setDisplay('0');
        return;
      }
      if (action === 'back') {
        const next = expression.length > 1 ? expression.slice(0, -1) : '0';
        setDisplay(next);
        return;
      }
      if (action === 'equals') {
        const result = evaluateExpression();
        if (result === null) {
          setDisplay('0');
        } else {
          setDisplay(result.toFixed(2));
        }
        return;
      }
      if (action === 'vat-plus') {
        const current = evaluateExpression();
        if (current !== null) {
          setDisplay((current * 1.21).toFixed(2));
        }
        return;
      }
      if (action === 'vat-minus') {
        const current = evaluateExpression();
        if (current !== null) {
          setDisplay((current / 1.21).toFixed(2));
        }
        return;
      }

      if (value) {
        const next = expression === '0' ? value : `${expression}${value}`;
        setDisplay(next);
      }
    };

    keypad?.addEventListener('click', (event) => {
      const target = event.target.closest('button');
      if (!target) return;
      const value = target.getAttribute('data-value');
      const action = target.getAttribute('data-action');
      applyCalculatorInput({ value, action });
    });

    calculatorCopyButton?.addEventListener('click', () => {
      if (!display || !amountInput) return;
      const evaluated = evaluateExpression();
      let parsed = evaluated;
      if (parsed === null) {
        const raw = display.value || '';
        const normalized = raw.replace(/[^0-9,.\-]/g, '').replace(',', '.');
        parsed = Number.parseFloat(normalized);
      }
      if (Number.isFinite(parsed)) {
        amountInput.value = parsed.toFixed(2);
        amountInput.focus();
      }
    });

    const setCalculatorActive = (active) => {
      calculatorActive = active;
    };

    const isEditableElement = (element) => {
      if (!element) return false;
      const tagName = element.tagName;
      return (
        tagName === 'INPUT' ||
        tagName === 'TEXTAREA' ||
        tagName === 'SELECT' ||
        element.isContentEditable
      );
    };

    const isWithinCalculator = (element) => {
      if (!calculatorCard || !element) return false;
      return calculatorCard.contains(element);
    };

    calculatorCard?.addEventListener('click', () => setCalculatorActive(true));
    calculatorCard?.addEventListener('focusin', () => setCalculatorActive(true));

    document.addEventListener('click', (event) => {
      if (!calculatorCard) return;
      if (!calculatorCard.contains(event.target)) {
        setCalculatorActive(false);
      }
    });

    document.addEventListener('focusin', (event) => {
      if (!calculatorCard) return;
      if (!calculatorCard.contains(event.target)) {
        setCalculatorActive(false);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (!display || !calculatorCard) return;
      if (event.ctrlKey || event.metaKey || event.altKey) return;
      const active = document.activeElement;
      const editable = isEditableElement(active);
      if (editable && !isWithinCalculator(active)) {
        return;
      }
      if (!calculatorActive && !isWithinCalculator(active)) {
        return;
      }

      const key = event.key;
      if (/^\d$/.test(key)) {
        event.preventDefault();
        applyCalculatorInput({ value: key });
        return;
      }
      if (['+', '-', '*', '/'].includes(key)) {
        event.preventDefault();
        applyCalculatorInput({ value: key });
        return;
      }
      if (key === 'Enter') {
        event.preventDefault();
        applyCalculatorInput({ action: 'equals' });
        return;
      }
      if (key === 'Backspace') {
        event.preventDefault();
        applyCalculatorInput({ action: 'back' });
        return;
      }
      if (key === 'Escape') {
        event.preventDefault();
        applyCalculatorInput({ action: 'clear' });
        return;
      }
      if (key === '.' || key === ',') {
        event.preventDefault();
        applyCalculatorInput({ value: '.' });
      }
    });
  })();
</script>

</body>
</html>
