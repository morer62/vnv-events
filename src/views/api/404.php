<?php

http_response_code(404);

echo json_encode([
    "message" => "The requested URL was not found on this server."
]);
