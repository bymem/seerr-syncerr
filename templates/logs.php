<?php
/** @var string[] $entries */

function ss_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ss_log_level(string $line): string
{
    return preg_match('/^\[[^\]]+\]\s+(INFO|WARN|ERROR):/', $line, $m) ? $m[1] : 'INFO';
}

$appVersion = (string) (getenv('APP_VERSION') ?: 'dev');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SeerrSyncerr — Action Log</title>
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, sans-serif; background: #17181c; color: #e6e6e6; max-width: 860px; margin: 2rem auto; padding: 0 1rem; }
  h1 { font-size: 1.4rem; }
  .hint { color: #888; font-size: .82rem; margin-top: .2rem; }
  .topbar { display: flex; justify-content: space-between; align-items: baseline; }
  .topbar a { color: #9ab; text-decoration: none; font-size: .9rem; }
  .topbar a:hover { text-decoration: underline; }
  .topbar-links { display: flex; gap: 1rem; align-items: baseline; }
  .version { font-size: .7rem; font-weight: normal; color: #777; margin-left: .5rem; }
  .tabs { display: flex; gap: .5rem; margin: 1rem 0 1.5rem; border-bottom: 1px solid #333; }
  .tab { color: #9ab; text-decoration: none; padding: .5rem .9rem; border-bottom: 2px solid transparent; font-size: .95rem; }
  .tab.active { color: #fff; border-bottom-color: #2a5c3a; }
  .tab:hover { color: #fff; }
  .refresh-btn { display: inline-block; background: #223344; color: #fff; border: 1px solid #335577; border-radius: 4px; padding: .35rem .8rem; text-decoration: none; font-size: .85rem; margin-bottom: 1rem; }
  .log-box { border: 1px solid #333; border-radius: 6px; background: #101114; overflow: hidden; }
  .log-line { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; padding: .4rem .6rem; border-bottom: 1px solid #262626; white-space: pre-wrap; word-break: break-word; }
  .log-line:last-child { border-bottom: none; }
  .log-line.level-ERROR { color: #ff9d9d; }
  .log-line.level-WARN { color: #ffcf80; }
  .log-line.level-INFO { color: #c7d0db; }
  .empty { padding: 1rem; color: #888; }
</style>
</head>
<body>

<div class="topbar">
  <h1>SeerrSyncerr <span class="version"><?= ss_e($appVersion) ?></span></h1>
  <div class="topbar-links">
    <a href="https://github.com/bymem/seerr-syncerr/releases" target="_blank" rel="noopener">Releases ↗</a>
    <a href="/logout">Sign out</a>
  </div>
</div>

<div class="tabs">
  <a href="/" class="tab">Settings</a>
  <a href="/logs" class="tab active">Action Log</a>
</div>

<p class="hint">
  Most recent first — kept for debugging what the webhook handler did on each
  report, capped at the last 500 entries. Not a full audit trail; the same
  lines are also in <code>docker logs</code>.
</p>

<a href="/logs" class="refresh-btn">Refresh</a>

<div class="log-box">
<?php if (empty($entries)): ?>
  <div class="empty">No activity logged yet.</div>
<?php else: ?>
  <?php foreach ($entries as $entry): ?>
  <div class="log-line level-<?= ss_e(ss_log_level($entry)) ?>"><?= ss_e($entry) ?></div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

</body>
</html>
