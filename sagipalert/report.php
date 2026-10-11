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
<h1>Report an Incident</h1>
<p class="sub">Provide as much accurate detail as you can. In a life-threatening emergency, also call 911 immediately.</p>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <div class="row">
    <div><label>Incident type</label>
      <select name="incident_type" required><option value="">Select…</option>
        <?php foreach (cfg('incident_types') as $t): ?><option <?= $v['incident_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Date and time of incident</label>
      <input type="datetime-local" name="incident_datetime" required max="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= e($v['incident_datetime']) ?>"></div>
  </div>
  <label>What happened?</label>
  <textarea name="description" required placeholder="Describe the situation, number of people affected, visible hazards…"><?= e($v['description']) ?></textarea>
  <div class="row">
    <div><label>Barangay</label>
      <select name="barangay" required><option value="">Select…</option>
        <?php foreach (cfg('barangays') as $b): ?><option <?= $v['barangay'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Street / landmark / address</label><input name="address" required value="<?= e($v['address']) ?>"></div>
  </div>
  <div class="row">
    <div><label>Latitude (optional)</label><input name="latitude" id="lat" inputmode="decimal" value="<?= e($v['latitude']) ?>"></div>
    <div><label>Longitude (optional)</label><input name="longitude" id="lng" inputmode="decimal" value="<?= e($v['longitude']) ?>"></div>
  </div>
  <p><button type="button" class="btn btn-alt btn-sm" onclick="useGPS()">Use my current location</button> <small class="muted" id="gpsmsg"></small></p>
  <label>Photo / PDF evidence (optional, JPG, PNG or PDF, max 5 MB each)</label>
  <input type="file" name="evidence[]" multiple accept=".jpg,.jpeg,.png,.pdf">
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
    m.textContent='Location added.';
  },function(){m.textContent='Could not get your location. You can type it in manually.';});
}
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
