<?php
/** @var bool $error */

function ss_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$appVersion = (string) (getenv('APP_VERSION') ?: 'dev');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SeerrSyncerr — Sign in</title>
<style>
  :root { color-scheme: dark; }
  body {
    font-family: system-ui, sans-serif; background: #17181c; color: #e6e6e6;
    display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0;
  }
  form {
    background: #1e1f24; border: 1px solid #333; border-radius: 8px;
    padding: 2rem; width: 100%; max-width: 320px;
  }
  h1 { font-size: 1.2rem; margin: 0 0 1.2rem; }
  label { display: block; margin: .8rem 0 .2rem; font-size: .9rem; color: #ccc; }
  input[type=text], input[type=password] {
    width: 100%; box-sizing: border-box; padding: .5rem .6rem; border-radius: 4px;
    border: 1px solid #444; background: #101114; color: #eee;
  }
  button {
    width: 100%; margin-top: 1.4rem; background: #2a5c3a; color: #fff; border: none;
    border-radius: 4px; padding: .7rem; font-size: 1rem; cursor: pointer;
  }
  .error { background: #4a2222; border: 1px solid #663333; padding: .6rem .8rem; border-radius: 4px; margin-bottom: 1rem; font-size: .9rem; }
  .version { display: block; font-size: .75rem; font-weight: normal; color: #666; margin-top: .3rem; }
</style>
</head>
<body>
<form method="post" action="/login">
  <h1>SeerrSyncerr<span class="version"><?= ss_e($appVersion) ?></span></h1>
  <?php if ($error): ?>
  <div class="error">Incorrect username or password.</div>
  <?php endif; ?>
  <label for="username">Username</label>
  <input type="text" id="username" name="username" autocomplete="username" autofocus required>
  <label for="password">Password</label>
  <input type="password" id="password" name="password" autocomplete="current-password" required>
  <button type="submit">Sign in</button>
</form>
</body>
</html>
