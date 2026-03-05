<?php
require 'db.php';
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    exit("Invalid media ID");
}
$mediaId = (int)$_GET['id'];
$stmt = $conn->prepare("SELECT file_name, mime_type, file_data FROM media_files WHERE media_id = ?");
$stmt->bind_param("i", $mediaId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    http_response_code(404);
    exit("Media not found");
}
$media = $result->fetch_assoc();
header("Content-Type: " . $media['mime_type']);
header("Content-Disposition: inline; filename=\"" . $media['file_name'] . "\"");
header("Content-Length: " . strlen($media['file_data']));
echo $media['file_data'];
$conn->close();
