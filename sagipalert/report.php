<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_role('resident', 'dispatcher', 'admin');

$errors = [];
$v = ['incident_type' => '', 'description' => '', 'barangay' => '', 'address' => '', 'latitude' => '', 'longitude' => '', 'incident_datetime' => date('Y-m-d\TH:i')];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));

    if (!in_array($v['incident_type'], cfg('incident_types'), true)) $errors[] = 'Choose a valid incident type.';
    if (mb_strlen($v['description']) < 10) $errors[] = 'Please describe the incident (at least 10 characters).';
    if (!in_array($v['barangay'], cfg('barangays'), true)) $errors[] = 'Choose a barangay.';
    if (mb_strlen($v['address']) < 3) $errors[] = 'Enter the street, landmark or address.';
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $v['incident_datetime']);
    if (!$dt) $errors[] = 'Enter a valid date and time.';
    elseif ($dt->getTimestamp() > time() + 300) $errors[] = 'Incident date and time cannot be in the future.';
    $lat = $lng = null;
    if ($v['latitude'] !== '' || $v['longitude'] !== '') {
        if (!is_numeric($v['latitude']) || !is_numeric($v['longitude']) || abs((float)$v['latitude']) > 90 || abs((float)$v['longitude']) > 180) {
            $errors[] = 'Latitude/longitude are invalid.';
        } else { $lat = (float)$v['latitude']; $lng = (float)$v['longitude']; }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ref = 'SA-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $pdo->prepare('INSERT INTO incidents (reference_no, reporter_id, incident_type, description, barangay, address, latitude, longitude, incident_datetime) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$ref, $u['id'], $v['incident_type'], $v['description'], $v['barangay'], $v['address'], $lat, $lng, $dt->format('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO status_history (incident_id, old_status, new_status, changed_by) VALUES (?,NULL,"Reported",?)')->execute([$id, $u['id']]);
            audit('incident_report', "Incident $ref reported ($v[incident_type], $v[barangay])", (int)$u['id']);
            notify_role(['dispatcher', 'admin'], "New $v[incident_type] report in $v[barangay] ($ref) awaiting verification.", $id);
            notify((int)$u['id'], "Your report $ref was received and is awaiting verification.", $id);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        $upErr = null;
        if (!empty($_FILES['evidence']['name'][0])) {
            foreach ($_FILES['evidence']['name'] as $i => $nm) {
                $f = ['name' => $nm, 'tmp_name' => $_FILES['evidence']['tmp_name'][$i], 'error' => $_FILES['evidence']['error'][$i], 'size' => $_FILES['evidence']['size'][$i]];
                $err = store_upload($f, $id, (int)$u['id'], 'Evidence');
                if ($err) $upErr = $err;
            }
        }
        flash($upErr ? "Report $ref submitted, but an attachment was not saved: $upErr" : "Report $ref submitted. Dispatchers have been notified.", $upErr ? 'info' : 'success');
        redirect('incident.php?id=' . $id);
    }
}
$pageTitle = 'Report Incident';
require __DIR__ . '/inc/header.php';
?>
<div class="page-header">
  <div>
    <span class="eyebrow">Resident reporting</span>
    <h1>Report an Incident</h1>
  </div>
</div>
<p class="sub">Provide as much accurate detail as you can. In a life-threatening emergency, also call 911 immediately.</p>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
<form id="report-form" method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <fieldset class="form-section">
    <legend>Incident details</legend>
    <div class="row">
      <div><label for="incident_type">Incident type</label>
        <select id="incident_type" name="incident_type" required>
          <option value="">Select…</option>
          <?php foreach (cfg('incident_types') as $t): ?><option value="<?= e($t) ?>" <?= $v['incident_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label for="incident_datetime">Date and time of incident</label>
        <input id="incident_datetime" type="datetime-local" name="incident_datetime" required max="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= e($v['incident_datetime']) ?>">
      </div>
    </div>
    <label for="description">What happened?</label>
    <textarea id="description" name="description" required placeholder="Describe the situation, number of people affected, visible hazards, and any immediate danger."><?= e($v['description']) ?></textarea>
  </fieldset>

  <fieldset class="form-section">
    <legend>Location</legend>
    <div class="row">
      <div><label for="barangay">Barangay</label>
        <select id="barangay" name="barangay" required>
          <option value="">Select…</option>
          <?php foreach (cfg('barangays') as $b): ?><option value="<?= e($b) ?>" <?= $v['barangay'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div><label for="address">Street / landmark / address</label>
        <input id="address" name="address" required value="<?= e($v['address']) ?>" placeholder="Nearest landmark, street, or house number">
      </div>
    </div>
    <div class="row">
      <div><label for="lat">Latitude (optional)</label><input id="lat" name="latitude" inputmode="decimal" value="<?= e($v['latitude']) ?>" placeholder="e.g. 10.2844"></div>
      <div><label for="lng">Longitude (optional)</label><input id="lng" name="longitude" inputmode="decimal" value="<?= e($v['longitude']) ?>" placeholder="e.g. 123.8391"></div>
    </div>
    <p class="form-help-row">
      <button type="button" class="btn btn-alt btn-sm" onclick="useGPS()">Use my current location</button>
      <small class="muted" id="gpsmsg" aria-live="polite">Optional: allow location access for a faster location entry.</small>
    </p>
  </fieldset>

  <fieldset class="form-section">
    <legend>Evidence</legend>
    <label for="evidence">Photo / PDF evidence (optional, JPG, PNG or PDF, max 5 MB each)</label>
    <input id="evidence" type="file" name="evidence[]" multiple accept=".jpg,.jpeg,.png,.pdf">
  </fieldset>

  <p><button class="btn" type="submit">Submit report</button></p>
</form>
<script>
function useGPS(){
  var m=document.getElementById('gpsmsg');
  if(!navigator.geolocation){m.textContent='Location is not supported by this browser.';return;}
  m.textContent='Locating…';
  navigator.geolocation.getCurrentPosition(function(p){
    document.getElementById('lat').value=p.coords.latitude.toFixed(7);
    document.getElementById('lng').value=p.coords.longitude.toFixed(7);
    m.textContent='Location added successfully.';
  },function(){m.textContent='Location access was denied. You can still enter coordinates manually.';});
}

document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('report-form');
  if (!form) return;
  form.addEventListener('submit', function () {
    const submit = form.querySelector('button[type="submit"]');
    if (!submit) return;
    submit.disabled = true;
    submit.textContent = 'Submitting…';
  });
});
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
