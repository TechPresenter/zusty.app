<?php

declare(strict_types=1);

/**
 * REST API tester.
 *
 * @var \Zuste\Core\View $this
 * @var list<string> $methods
 * @var string $baseUrl
 * @var string $transport
 * @var bool   $selfBlocking
 * @var array<string,mixed>|null $result
 * @var array<string,string> $submitted
 * @var list<array{method:string,path:string,label:string}> $examples
 */

$this->extend('admin.layouts.app');
$this->start('content');

$statusTone = static fn (int $code): string => match (true) {
    $code >= 500 => 'error',
    $code >= 400 => 'warning',
    $code >= 300 => 'info',
    $code >= 200 => 'success',
    default      => 'neutral',
};

?>

<?php if ($baseUrl === ''): ?>
  <div class="callout callout--error mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>The tester has no host to call.</strong>
      It only ever calls the address in <code class="mono">APP_URL</code>, and that value is missing or is not a
      plain <code class="mono">http://</code> or <code class="mono">https://</code> address. Set it in the
      environment file — for example <code class="mono">APP_URL=https://zusty.app</code> — and reload this page.
      The host is never taken from the browser, so there is nothing to fall back to.
    </div>
  </div>
<?php else: ?>
  <div class="callout callout--info mb-5">
    <?= $this->icon('info', 'ic ic--sm') ?>
    <div>
      <strong>The request is issued by the server, not by your browser.</strong>
      That means it works even when this machine cannot reach the API host directly. For the same reason it is
      deliberately fenced in: only paths under <code class="mono">/api/</code> are allowed, the host is fixed at
      <code class="mono"><?= $this->e($baseUrl) ?></code>, redirects are not followed and the call gives up after 10 seconds.
      Transport in use: <strong><?= $this->e($transport) ?></strong>.
    </div>
  </div>
<?php endif; ?>

<?php if ($selfBlocking): ?>
  <div class="callout callout--warning mb-5">
    <?= $this->icon('alert', 'ic ic--sm') ?>
    <div>
      <strong>This panel is running on PHP's built-in development server.</strong>
      It serves one request at a time, so a call the panel makes back to itself cannot be answered until
      the panel's own request finishes — the tester will wait the full 10 seconds and then report a timeout.
      Run the panel behind Apache or nginx to use the tester, or start the dev server with
      <code class="mono">PHP_CLI_SERVER_WORKERS</code> above 1 on Linux or macOS.
    </div>
  </div>
<?php endif; ?>

<div class="grid grid--sidebar">

  <div class="card">
    <div class="card__head"><h2 class="h3">Request</h2></div>

    <div class="card__body">
      <form method="post" action="/admin/developer/tester/run" class="grid" id="tester-form">
        <?= $this->csrf() ?>

        <div class="row gap-2">
          <div class="field" style="max-width:140px">
            <label class="field__label" for="method">Method</label>
            <select class="select" id="method" name="method">
              <?php foreach ($methods as $method): ?>
                <option value="<?= $this->e($method) ?>" <?= ($submitted['method'] ?? 'GET') === $method ? 'selected' : '' ?>>
                  <?= $this->e($method) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field flex-1">
            <label class="field__label" for="path">Path <span class="req">*</span></label>
            <input class="input mono" id="path" name="path" required maxlength="500"
                   placeholder="/api/v1/health" value="<?= $this->e($submitted['path'] ?? '/api/v1/health') ?>">
            <p class="field__hint">
              Relative to <code class="mono"><?= $this->e($baseUrl !== '' ? $baseUrl : 'the address in APP_URL') ?></code>.
              Must start with <code class="mono">/api/</code>.
            </p>
          </div>
        </div>

        <div class="field">
          <label class="field__label" for="token">Bearer token (optional)</label>
          <input class="input mono" id="token" name="token" autocomplete="off" spellcheck="false"
                 placeholder="eyJhbGciOi…" value="<?= $this->e($submitted['token'] ?? '') ?>">
          <p class="field__hint">Sent as <code class="mono">Authorization: Bearer …</code>. It is never written to the activity log.</p>
        </div>

        <div class="field">
          <label class="field__label" for="body">JSON body (optional)</label>
          <textarea class="textarea mono" id="body" name="body" rows="8" spellcheck="false"
                    placeholder='{"email":"someone@example.com"}'><?= $this->e($submitted['body'] ?? '') ?></textarea>
          <p class="field__hint" id="body-hint">Checked for valid JSON before anything is sent. GET and DELETE cannot carry a body.</p>
        </div>

        <div class="row gap-2">
          <button type="submit" class="btn btn--primary" id="tester-send">
            <?= $this->icon('external', 'ic ic--sm') ?> Send request
          </button>
          <button type="button" class="btn btn--ghost btn--sm" id="tester-format">Format JSON</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2 class="h3">Examples</h2></div>
    <div class="card__body">
      <p class="small muted mb-3">Endpoints that exist in this application. Selecting one fills the form.</p>

      <div class="grid">
        <?php foreach ($examples as $example): ?>
          <button type="button" class="btn btn--outline btn--sm dev-example"
                  data-method="<?= $this->e($example['method']) ?>"
                  data-path="<?= $this->e($example['path']) ?>">
            <span class="dev-method dev-method--<?= $this->e(strtolower($example['method'])) ?>"><?= $this->e($example['method']) ?></span>
            <span class="mono tiny truncate"><?= $this->e($example['path']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <p class="tiny muted mt-4">
        Endpoints behind <code class="mono">auth</code> need a bearer token. Sign a user in through
        <code class="mono">POST /api/v1/auth/login</code> and paste the access token above.
      </p>
    </div>
  </div>
</div>

<div id="tester-result" class="mt-5">
  <?php if ($result !== null): ?>
    <div class="card">
      <div class="card__head">
        <div class="row gap-3">
          <h2 class="h3">Response</h2>
          <?php if ((int) $result['status'] > 0): ?>
            <span class="chip chip--<?= $statusTone((int) $result['status']) ?>">
              <span class="chip__dot"></span><?= (int) $result['status'] ?>
            </span>
          <?php endif; ?>
          <?php if ((float) $result['duration_ms'] > 0): ?>
            <span class="chip chip--neutral"><?= $this->e((string) $result['duration_ms']) ?> ms</span>
          <?php endif; ?>
          <?php if ((int) $result['size'] > 0): ?>
            <span class="chip chip--neutral"><?= $this->number((int) $result['size']) ?> bytes</span>
          <?php endif; ?>
        </div>
        <?php if ($result['url'] !== ''): ?>
          <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= $this->e($result['url']) ?>">
            <?= $this->icon('external', 'ic ic--sm') ?> Copy URL
          </button>
        <?php endif; ?>
      </div>

      <div class="card__body">
        <?php if ($result['error'] !== ''): ?>
          <div class="callout callout--error mb-4">
            <?= $this->icon('alert', 'ic ic--sm') ?>
            <div><?= $this->e($result['error']) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($result['url'] !== ''): ?>
          <p class="label mb-2">URL</p>
          <p class="mono small mb-4"><?= $this->e($result['url']) ?></p>
        <?php endif; ?>

        <?php if (($result['headers'] ?? []) !== []): ?>
          <p class="label mb-2">Response headers</p>
          <pre class="dev-code mb-4"><?= $this->e(implode("\n", $result['headers'])) ?></pre>
        <?php endif; ?>

        <?php if ($result['body'] !== ''): ?>
          <p class="label mb-2">
            Body
            <?php if ($result['truncated']): ?><span class="tiny text-warning">truncated for display</span><?php endif; ?>
          </p>
          <pre class="dev-code"><?= $this->e($result['pretty'] !== '' ? $result['pretty'] : $result['body']) ?></pre>
        <?php elseif ($result['error'] === ''): ?>
          <p class="small muted">The response had an empty body.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php $this->stop(); ?>

<?php $this->start('scripts'); ?>
<style>
  .dev-method {
    display: inline-block; margin-right: 6px; padding: 1px 6px;
    border-radius: var(--r-sm); font-size: 10.5px; font-weight: 700;
    letter-spacing: .04em; background: var(--surface-variant); color: var(--on-surface-variant);
  }
  .dev-method--get    { background: color-mix(in srgb, var(--secondary) 14%, transparent); color: var(--secondary); }
  .dev-method--post   { background: color-mix(in srgb, var(--tertiary) 16%, transparent); color: var(--tertiary); }
  .dev-method--put,
  .dev-method--patch  { background: color-mix(in srgb, var(--warning) 16%, transparent); color: var(--warning); }
  .dev-method--delete { background: color-mix(in srgb, var(--error) 14%, transparent); color: var(--error); }

  .dev-example { justify-content: flex-start; text-align: left; }

  .dev-code {
    margin: 0; padding: 12px; border-radius: var(--r);
    background: var(--surface-variant); border: 1px solid var(--outline-variant);
    font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.55; white-space: pre-wrap; word-break: break-word;
    max-height: 420px; overflow: auto;
  }
</style>
<script>
(function () {
  const form    = document.getElementById('tester-form');
  const panel   = document.getElementById('tester-result');
  const method  = document.getElementById('method');
  const path    = document.getElementById('path');
  const body    = document.getElementById('body');
  const send    = document.getElementById('tester-send');
  const format  = document.getElementById('tester-format');

  if (!form || !panel) return;

  document.querySelectorAll('.dev-example').forEach(function (button) {
    button.addEventListener('click', function () {
      method.value = button.dataset.method;
      path.value   = button.dataset.path;
      body.value   = '';
      path.focus();
    });
  });

  format.addEventListener('click', function () {
    const raw = body.value.trim();
    if (raw === '') return;

    try {
      body.value = JSON.stringify(JSON.parse(raw), null, 2);
    } catch (error) {
      window.Admin.toast('That body is not valid JSON: ' + error.message, 'error');
    }
  });

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function statusTone(code) {
    if (code >= 500) return 'error';
    if (code >= 400) return 'warning';
    if (code >= 300) return 'info';
    if (code >= 200) return 'success';
    return 'neutral';
  }

  function render(result) {
    panel.textContent = '';

    const card = element('div', 'card');
    const head = element('div', 'card__head');
    const meta = element('div', 'row gap-3');

    meta.appendChild(element('h2', 'h3', 'Response'));

    if (result.status > 0) {
      meta.appendChild(element('span', 'chip chip--' + statusTone(result.status), result.status));
    }
    if (result.duration_ms > 0) {
      meta.appendChild(element('span', 'chip chip--neutral', result.duration_ms + ' ms'));
    }
    if (result.size > 0) {
      meta.appendChild(element('span', 'chip chip--neutral', result.size + ' bytes'));
    }

    head.appendChild(meta);
    card.appendChild(head);

    const content = element('div', 'card__body');

    if (result.error) {
      const callout = element('div', 'callout callout--error mb-4');
      callout.appendChild(element('div', null, result.error));
      content.appendChild(callout);
    }

    if (result.url) {
      content.appendChild(element('p', 'label mb-2', 'URL'));
      content.appendChild(element('p', 'mono small mb-4', result.url));
    }

    if (result.headers && result.headers.length) {
      content.appendChild(element('p', 'label mb-2', 'Response headers'));
      content.appendChild(element('pre', 'dev-code mb-4', result.headers.join('\n')));
    }

    if (result.body) {
      content.appendChild(element('p', 'label mb-2', result.truncated ? 'Body (truncated for display)' : 'Body'));
      content.appendChild(element('pre', 'dev-code', result.pretty || result.body));
    } else if (!result.error) {
      content.appendChild(element('p', 'small muted', 'The response had an empty body.'));
    }

    card.appendChild(content);
    panel.appendChild(card);
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  form.addEventListener('submit', async function (event) {
    // Progressive enhancement: without JavaScript the form posts normally and
    // the controller re-renders this page with the result.
    event.preventDefault();

    const raw = body.value.trim();

    if (raw !== '') {
      try {
        JSON.parse(raw);
      } catch (error) {
        window.Admin.toast('The request body is not valid JSON: ' + error.message, 'error');
        body.focus();
        return;
      }
    }

    send.classList.add('btn--loading');
    send.disabled = true;

    panel.textContent = '';
    const waiting = element('div', 'card');
    const waitingBody = element('div', 'card__body');
    waitingBody.appendChild(element('div', 'skeleton', ''));
    waitingBody.appendChild(element('p', 'small muted mt-3', 'Waiting for the server to complete the call…'));
    waiting.appendChild(waitingBody);
    panel.appendChild(waiting);

    try {
      const payload = await window.Admin.request(form.action, {
        method: 'POST',
        body: new FormData(form)
      });

      render(payload.data || {});
    } catch (error) {
      render({
        status: 0, duration_ms: 0, size: 0, headers: [], body: '', pretty: '',
        url: '', truncated: false, error: error.message
      });
    } finally {
      send.classList.remove('btn--loading');
      send.disabled = false;
    }
  });
})();
</script>
<?php $this->stop(); ?>
