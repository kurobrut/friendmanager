<?php

header('Content-Type: application/json');

$data = json_decode(
    file_get_contents("php://input"),
    true
);

$action = $data['action'] ?? '';
$cookie = trim($data['cookie'] ?? '');

function robloxRequest(
    $url,
    $method = 'GET',
    $cookie = '',
    $csrf = '',
    $body = null
){

    $headers = [
        "Content-Type: application/json",
        "User-Agent: Mozilla/5.0"
    ];

    if($csrf){
        $headers[] = "X-CSRF-TOKEN: $csrf";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_COOKIE, ".ROBLOSECURITY=$cookie");
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if($body !== null){
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response  = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersText = substr($response, 0, $headerSize);
    $bodyText    = substr($response, $headerSize);
    $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    preg_match('/x-csrf-token:\s*(.*)/i', $headersText, $matches);
    $newToken = trim($matches[1] ?? '');

    curl_close($ch);

    return [
        'status' => $status,
        'body'   => $bodyText,
        'csrf'   => $newToken
    ];
}

if($action === 'friends'){

    $user = robloxRequest(
        "https://users.roblox.com/v1/users/authenticated",
        'GET',
        $cookie
    );

    if($user['status'] !== 200){
        echo json_encode(['error' => 'Invalid cookie']);
        exit;
    }

    $userData = json_decode($user['body'], true);
    $userId   = $userData['id'];

    $friendsReq  = robloxRequest(
        "https://friends.roblox.com/v1/users/$userId/friends",
        'GET',
        $cookie
    );

    $friendsData = json_decode($friendsReq['body'], true);
    $friends     = $friendsData['data'] ?? [];

    if(empty($friends)){
        echo json_encode(['friends' => [], 'totalFriends' => 0, 'avatarsLoaded' => 0]);
        exit;
    }

    $ids = array_column($friends, 'id');

    // USERNAME API — chunked, max 100 per request
    $userMap = [];
    foreach(array_chunk($ids, 100) as $chunk){
        $req  = robloxRequest(
            "https://users.roblox.com/v1/users",
            'POST', $cookie, '',
            ["userIds" => $chunk, "excludeBannedUsers" => false]
        );
        $d = json_decode($req['body'], true);
        foreach(($d['data'] ?? []) as $u){
            $userMap[$u['id']] = [
                'username'    => $u['name'],
                'displayName' => $u['displayName']
            ];
        }
    }

    // THUMBNAILS API — chunked, max 100 per request
    $thumbMap = [];
    foreach(array_chunk($ids, 100) as $chunk){
        $req  = robloxRequest(
            "https://thumbnails.roblox.com/v1/users/avatar-headshot?userIds="
            . implode(',', $chunk)
            . "&size=420x420&format=Png&isCircular=false",
            'GET', ''
        );
        $d = json_decode($req['body'], true);
        foreach(($d['data'] ?? []) as $thumb){
            if(isset($thumb['imageUrl'])){
                $thumbMap[$thumb['targetId']] = $thumb['imageUrl'];
            }
        }
    }

    foreach($friends as &$friend){
        $friend['username']    = $userMap[$friend['id']]['username']    ?? 'Unknown';
        $friend['displayName'] = $userMap[$friend['id']]['displayName'] ?? 'Unknown';
        $friend['avatar']      = $thumbMap[$friend['id']]               ?? '';
    }

    echo json_encode([
        'friends'      => $friends,
        'totalFriends' => count($friends),
        'avatarsLoaded' => count(array_filter($friends, fn($f) => !empty($f['avatar'])))
    ]);

    exit;
}

if($action === 'unfriend'){

    $userId = $data['userId'] ?? null;

    if(!$userId){
        echo json_encode(['success' => false, 'error' => 'Missing userId']);
        exit;
    }

    $url = "https://friends.roblox.com/v1/users/$userId/unfriend";

    // Step 1: get CSRF token
    $first = robloxRequest($url, 'POST', $cookie);
    $csrf  = $first['csrf'];

    // Step 2: retry with CSRF — up to 3 attempts
    $attempts = 0;
    $success  = false;
    $rateLimited = false;

    while($attempts < 3 && !$success){

        $attempts++;

        $res = robloxRequest($url, 'POST', $cookie, $csrf, []);

        if($res['status'] === 200){
            $success = true;

        } elseif($res['status'] === 429){
            $rateLimited = true;
            sleep(3); // back off before retry

        } elseif($res['status'] === 403){
            // CSRF may have rotated — grab fresh token and retry
            $csrf = $res['csrf'] ?: $csrf;
            usleep(500000); // 0.5s

        } else {
            // Other error — short wait then retry
            usleep(500000);
        }
    }

    echo json_encode([
        'success'     => $success,
        'rateLimited' => $rateLimited,
        'attempts'    => $attempts,
        'status'      => $res['status'] ?? 0
    ]);

    exit;
}
