<?php
header('Content-Type: application/json');
echo json_encode([
  'status' => 'ok',
  'service' => 'PrakCheck PHP Backend',
  'timestamp' => date('c')
]);
