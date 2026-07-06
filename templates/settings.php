<?php
/** @var \SeerrSyncerr\Config $config */
/** @var string $webhookUrl */
/** @var bool $saved */
/** @var string $csrfToken */

$mainLanguages = (array) $config->get('subtitles.main_languages', []);
$languageKeywords = (array) $config->get('subtitles.language_keywords', []);
$syncKeywords = (array) $config->get('subtitles.sync_keywords', []);
$translatorAdapter = (string) $config->get('translator.adapter', 'none');

$hasSeerrKey = $config->get('seerr.api_key', '') !== '';
$hasRadarrKey = $config->get('radarr.api_key', '') !== '';
$hasSonarrKey = $config->get('sonarr.api_key', '') !== '';
$hasBazarrKey = $config->get('bazarr.api_key', '') !== '';

function ss_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SeerrSyncerr — Settings</title>
<style>
  :root { color-scheme: dark; }
  body { font-family: system-ui, sans-serif; background: #17181c; color: #e6e6e6; max-width: 860px; margin: 2rem auto; padding: 0 1rem; }
  h1 { font-size: 1.4rem; }
  h2 { font-size: 1.05rem; margin-top: 2.2rem; border-bottom: 1px solid #333; padding-bottom: .3rem; }
  fieldset { border: 1px solid #333; border-radius: 6px; margin-bottom: 1rem; padding: 1rem; }
  legend { padding: 0 .4rem; color: #aaa; }
  label { display: block; margin: .6rem 0 .2rem; font-size: .9rem; color: #ccc; }
  input[type=text], input[type=url], input[type=password], select {
    width: 100%; box-sizing: border-box; padding: .45rem .5rem; border-radius: 4px;
    border: 1px solid #444; background: #101114; color: #eee;
  }
  .row { display: flex; gap: .5rem; margin-bottom: .4rem; align-items: center; }
  .row input { flex: 1; }
  .row button, .add-btn, .save-btn { cursor: pointer; }
  .remove-btn { background: #442222; color: #fff; border: 1px solid #663333; border-radius: 4px; padding: .35rem .6rem; }
  .move-btn { background: #2a2c33; color: #fff; border: 1px solid #444; border-radius: 4px; padding: .35rem .55rem; line-height: 1; }
  .add-btn { background: #223344; color: #fff; border: 1px solid #335577; border-radius: 4px; padding: .35rem .8rem; margin-top: .3rem; }
  .save-btn { background: #2a5c3a; color: #fff; border: none; border-radius: 4px; padding: .7rem 1.4rem; font-size: 1rem; margin: 1.5rem 0; }
  .readonly-box { background: #101114; border: 1px solid #333; border-radius: 4px; padding: .5rem .6rem; font-family: monospace; word-break: break-all; }
  .hint { color: #888; font-size: .82rem; margin-top: .2rem; }
  .banner { background: #1f3d27; border: 1px solid #2a5c3a; padding: .6rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
  .topbar { display: flex; justify-content: space-between; align-items: baseline; }
  .topbar a { color: #9ab; text-decoration: none; font-size: .9rem; }
  .topbar a:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="topbar">
  <h1>SeerrSyncerr</h1>
  <a href="/logout">Sign out</a>
</div>
<p class="hint">Subtitle issue bridge for Seerr + Bazarr.</p>

<?php if ($saved): ?>
<div class="banner">Settings saved.</div>
<?php endif; ?>

<form method="post" action="/save">
  <input type="hidden" name="csrf_token" value="<?= ss_e($csrfToken) ?>">

  <h2>Seerr</h2>
  <fieldset>
    <label for="seerr_url">Seerr URL</label>
    <input type="url" id="seerr_url" name="seerr_url" value="<?= ss_e($config->get('seerr.url', '')) ?>" placeholder="http://seerr:5055" required>

    <label for="seerr_api_key">Seerr API key</label>
    <input type="password" id="seerr_api_key" name="seerr_api_key" placeholder="<?= $hasSeerrKey ? '•••••••• (leave blank to keep)' : 'API key' ?>" autocomplete="new-password">
  </fieldset>

  <h2>Radarr</h2>
  <fieldset>
    <label for="radarr_url">Radarr URL</label>
    <input type="url" id="radarr_url" name="radarr_url" value="<?= ss_e($config->get('radarr.url', '')) ?>" placeholder="http://radarr:7878">

    <label for="radarr_api_key">Radarr API key</label>
    <input type="password" id="radarr_api_key" name="radarr_api_key" placeholder="<?= $hasRadarrKey ? '•••••••• (leave blank to keep)' : 'API key' ?>" autocomplete="new-password">
  </fieldset>

  <h2>Sonarr</h2>
  <fieldset>
    <label for="sonarr_url">Sonarr URL</label>
    <input type="url" id="sonarr_url" name="sonarr_url" value="<?= ss_e($config->get('sonarr.url', '')) ?>" placeholder="http://sonarr:8989">

    <label for="sonarr_api_key">Sonarr API key</label>
    <input type="password" id="sonarr_api_key" name="sonarr_api_key" placeholder="<?= $hasSonarrKey ? '•••••••• (leave blank to keep)' : 'API key' ?>" autocomplete="new-password">
  </fieldset>

  <h2>Bazarr</h2>
  <fieldset>
    <label for="bazarr_url">Bazarr URL</label>
    <input type="url" id="bazarr_url" name="bazarr_url" value="<?= ss_e($config->get('bazarr.url', '')) ?>" placeholder="http://bazarr:6767">

    <label for="bazarr_api_key">Bazarr API key</label>
    <input type="password" id="bazarr_api_key" name="bazarr_api_key" placeholder="<?= $hasBazarrKey ? '•••••••• (leave blank to keep)' : 'API key' ?>" autocomplete="new-password">
  </fieldset>

  <h2>Main languages</h2>
  <p class="hint">
    Every language listed here gets fixed whenever a report's comment doesn't
    match one of the language keywords below — this isn't a fallback chain
    where only the first one applies, all of them are attempted. The order
    just controls the sequence they're processed and reported in, so use the
    ↑/↓ buttons to arrange them however you'd like to read the summary
    comment Seerr gets back.
  </p>
  <p class="hint">
    Type the exact two-letter code <strong>Bazarr</strong> uses for that
    language (e.g. <code>en</code>, <code>da</code>, <code>de</code>,
    <code>fr</code>, <code>es</code>) — check Bazarr's own
    <strong>Settings → Languages</strong> page for the codes your profiles
    are actually configured with. Whatever you type here is passed straight
    to Bazarr's API, so it needs to match Bazarr's codes specifically, not
    Seerr's language names or a Radarr/Sonarr profile label.
  </p>
  <fieldset>
    <div id="main-languages-list">
      <?php foreach ($mainLanguages as $lang): ?>
      <div class="row">
        <button type="button" class="move-btn" onclick="ssMoveRow(this, -1)" title="Move up">↑</button>
        <button type="button" class="move-btn" onclick="ssMoveRow(this, 1)" title="Move down">↓</button>
        <input type="text" name="main_languages[]" value="<?= ss_e($lang) ?>" placeholder="e.g. da">
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="add-btn" onclick="ssAddRow('main-languages-list', ssMainLanguageRow())">Add language</button>
  </fieldset>

  <h2>Language keywords <span class="hint">(optional)</span></h2>
  <p class="hint">A word in the report's comment that means "fix exactly this language" — e.g. "english" → "en".</p>
  <fieldset>
    <div id="language-keywords-list">
      <?php foreach ($languageKeywords as $keyword => $code): ?>
      <div class="row">
        <input type="text" name="language_keyword_key[]" value="<?= ss_e($keyword) ?>" placeholder="keyword, e.g. english">
        <input type="text" name="language_keyword_value[]" value="<?= ss_e($code) ?>" placeholder="code, e.g. en">
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="add-btn" onclick="ssAddRow('language-keywords-list', ssKeywordRow())">Add keyword</button>
  </fieldset>

  <h2>Sync keywords <span class="hint">(optional)</span></h2>
  <p class="hint">Phrases meaning "realign the existing file", not "replace it" — e.g. "out of sync", "timing".</p>
  <fieldset>
    <div id="sync-keywords-list">
      <?php foreach ($syncKeywords as $phrase): ?>
      <div class="row">
        <input type="text" name="sync_keywords[]" value="<?= ss_e($phrase) ?>" placeholder="e.g. out of sync">
        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
      </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="add-btn" onclick="ssAddRow('sync-keywords-list', ssSyncKeywordRow())">Add phrase</button>
  </fieldset>

  <h2>Auto-translate interop <span class="hint">(optional)</span></h2>
  <fieldset>
    <label for="translator_adapter">Auto-translate tool</label>
    <select id="translator_adapter" name="translator_adapter">
      <?php
      $adapterOptions = [
          'none' => 'None',
          'bazarr_ai_translate' => 'Bazarr-AI-Translate',
          'bazarr_auto_translate' => 'Bazarr_AutoTranslate',
          'ai_subtitle_translator' => 'ai-subtitle-translator',
          'custom' => 'Custom',
      ];
      foreach ($adapterOptions as $value => $labelText):
      ?>
      <option value="<?= ss_e($value) ?>" <?= $translatorAdapter === $value ? 'selected' : '' ?>><?= ss_e($labelText) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="translator_tool_url">Tool URL <span class="hint">(ai-subtitle-translator / Custom only)</span></label>
    <input type="url" id="translator_tool_url" name="translator_tool_url" value="<?= ss_e($config->get('translator.tool_url', '')) ?>" placeholder="http://ai-subtitle-translator:8000">

    <label>
      <input type="checkbox" name="translator_custom_callable" <?= $config->get('translator.custom_callable', false) ? 'checked' : '' ?> style="width:auto;display:inline-block;">
      Custom tool is callable on demand
    </label>

    <label for="translator_source_language">Source language</label>
    <input type="text" id="translator_source_language" name="translator_source_language" value="<?= ss_e($config->get('translator.source_language', 'en')) ?>" placeholder="en">
    <p class="hint">The language the external tool translates FROM — used to fetch a fresh source file when a translated subtitle needs resetting.</p>

    <label for="translator_filename_pattern">Externally-translated filename pattern <span class="hint">(regex)</span></label>
    <input type="text" id="translator_filename_pattern" name="translator_filename_pattern" value="<?= ss_e($config->get('translator.filename_pattern', '')) ?>" placeholder="/\.ai-translated\.[a-z]{2}\.srt$/">
  </fieldset>

  <h2>Webhook</h2>
  <fieldset>
    <label>Webhook URL <span class="hint">(paste into Seerr → Settings → Notifications → Webhook)</span></label>
    <div class="readonly-box"><?= ss_e($webhookUrl) ?></div>

    <label style="margin-top:1rem;">Webhook secret <span class="hint">(paste into Seerr's Authorization Header field)</span></label>
    <div class="readonly-box"><?= ss_e($config->get('webhook.secret', '')) ?></div>
  </fieldset>

  <button type="submit" class="save-btn">Save settings</button>
</form>

<script>
function ssAddRow(containerId, html) {
  const container = document.getElementById(containerId);
  const wrapper = document.createElement('div');
  wrapper.innerHTML = html;
  container.appendChild(wrapper.firstElementChild);
}

function ssMoveRow(button, direction) {
  const row = button.closest('.row');
  const sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;
  if (!sibling) {
    return;
  }
  if (direction < 0) {
    row.parentElement.insertBefore(row, sibling);
  } else {
    row.parentElement.insertBefore(sibling, row);
  }
}

function ssMainLanguageRow() {
  return '<div class="row">'
    + '<button type="button" class="move-btn" onclick="ssMoveRow(this, -1)" title="Move up">↑</button>'
    + '<button type="button" class="move-btn" onclick="ssMoveRow(this, 1)" title="Move down">↓</button>'
    + '<input type="text" name="main_languages[]" placeholder="e.g. en">'
    + '<button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>'
    + '</div>';
}

function ssKeywordRow() {
  return '<div class="row">'
    + '<input type="text" name="language_keyword_key[]" placeholder="keyword, e.g. english">'
    + '<input type="text" name="language_keyword_value[]" placeholder="code, e.g. en">'
    + '<button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>'
    + '</div>';
}

function ssSyncKeywordRow() {
  return '<div class="row">'
    + '<input type="text" name="sync_keywords[]" placeholder="e.g. out of sync">'
    + '<button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>'
    + '</div>';
}
</script>

</body>
</html>
