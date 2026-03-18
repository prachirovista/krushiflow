<?php
// php -S localhost:8080 -t public - TO run the project

declare(strict_types=1);
use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        // CORS Pre-Flight OPTIONS Request Handler
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->group('/users', function (Group $group) {
        $group->get('', ListUsersAction::class);
        $group->get('/{id}', ViewUserAction::class);
    });


$app->get('/test-db', function ($request, $response) {

    $pdo = $this->get(PDO::class);

    $stmt = $pdo->query("SELECT 1");

    $response->getBody()->write(json_encode([
        "status" => "Database Connected"
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});
$app->post('/auth', function (Request $request, Response $response) use ($app) {

$pdo = $app->getContainer()->get(PDO::class);
$data = $request->getParsedBody();

$mobile = $data['mobile'] ?? '';
$firebase_uid = $data['firebase_uid'] ?? '';

$device_type = $data['device_type'] ?? '';
$device_id = $data['device_id'] ?? '';
$firebase_token = $data['firebase_token'] ?? '';

if(empty($mobile) || empty($firebase_uid)){
    $response->getBody()->write(json_encode([
        "status"=>false,
        "message"=>"Mobile and Firebase UID required"
    ]));
    return $response->withHeader('Content-Type','application/json');
}

/*
CHECK USER
*/

$stmt = $pdo->prepare("SELECT id,role,name FROM users WHERE mobile=?");
$stmt->execute([$mobile]);
$user = $stmt->fetch();

if(!$user){

    $stmt = $pdo->prepare("
        INSERT INTO users (mobile,firebase_uid,role)
        VALUES (?,?,?)
    ");

    $stmt->execute([$mobile,$firebase_uid,'farmer']);

    $user_id = $pdo->lastInsertId();
    $role = "farmer";

} else {

    $user_id = $user['id'];
    $role = $user['role'];
}

/*
GENERATE SESSION TOKEN
*/

$session_token = bin2hex(random_bytes(32));

/*
DEVICE CHECK
*/

$stmt = $pdo->prepare("
    SELECT id FROM user_devices
    WHERE user_id=? AND device_id=?
");

$stmt->execute([$user_id,$device_id]);
$device = $stmt->fetch();

if($device){

    $stmt = $pdo->prepare("
        UPDATE user_devices
        SET session_token=?, firebase_token=?, device_type=?
        WHERE id=?
    ");

    $stmt->execute([
        $session_token,
        $firebase_token,
        $device_type,
        $device['id']
    ]);

} else {

    $stmt = $pdo->prepare("
        INSERT INTO user_devices
        (user_id,device_type,device_id,firebase_token,session_token)
        VALUES (?,?,?,?,?)
    ");

    $stmt->execute([
        $user_id,
        $device_type,
        $device_id,
        $firebase_token,
        $session_token
    ]);
}

$response->getBody()->write(json_encode([
    "status"=>true,
    "user_id"=>$user_id,
    "role"=>$role,
    "session_token"=>$session_token
]));

return $response->withHeader('Content-Type','application/json');

});
$app->get('/profile', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    $stmt = $pdo->prepare("
        SELECT u.*
        FROM users u
        JOIN user_devices d ON d.user_id=u.id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    $response->getBody()->write(json_encode($user));

    return $response->withHeader('Content-Type','application/json');
});

$app->post('/update-profile', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);
    $data = $request->getParsedBody();

    // Get Authorization header
    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

$name = $data['name'] ?? '';
$state = $data['state'] ?? '';
$district = $data['district'] ?? '';
$address = $data['address'] ?? '';
$village = $data['village'] ?? '';

    if(empty($name)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Name is required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    FIND USER FROM SESSION TOKEN
    */

    $stmt = $pdo->prepare("
        SELECT user_id FROM user_devices
        WHERE session_token=?
    ");

    $stmt->execute([$token]);
    $device = $stmt->fetch();

    if(!$device){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    UPDATE PROFILE
    */

   $stmt = $pdo->prepare("
UPDATE users
SET name=?, state=?, district=?, address=?, village=?
WHERE id=?
");
   $stmt->execute([
$name,
$state,
$district,
$address,
$village,
$device['user_id']
]);

    /*
    RETURN UPDATED PROFILE
    */

    $stmt = $pdo->prepare("
        SELECT id,mobile,name,state,district,address,village
        FROM users
        WHERE id=?
    ");

    $stmt->execute([$device['user_id']]);
    $user = $stmt->fetch();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Profile updated successfully",
        "user"=>$user
    ]));

    return $response->withHeader('Content-Type','application/json');
});

$app->post('/logout', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    // Get Authorization token
    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    CHECK SESSION
    */

    $stmt = $pdo->prepare("
        SELECT id FROM user_devices
        WHERE session_token=?
    ");

    $stmt->execute([$token]);
    $device = $stmt->fetch();

    if(!$device){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    DELETE SESSION
    */

    $stmt = $pdo->prepare("
        DELETE FROM user_devices
        WHERE session_token=?
    ");

    $stmt->execute([$token]);

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Logged out successfully"
    ]));

    return $response->withHeader('Content-Type','application/json');
});


$app->post('/farmer/add', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);
    $data = $request->getParsedBody();

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    OPTIONAL: ONLY ADMIN CAN ADD FARMER
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can add farmers"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    FARMER DATA
    */

    $name     = $data['name'] ?? '';
    $mobile   = $data['mobile'] ?? '';
    $village  = $data['village'] ?? '';

    $state    = $data['state'] ?? null;
    $district = $data['district'] ?? null;
    $address  = $data['address'] ?? null;

    if(empty($name) || empty($mobile) || empty($village)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Name, Mobile and Village are required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    INSERT FARMER
    */

    $stmt = $pdo->prepare("
        INSERT INTO users
        (name,mobile,village,state,district,address,role,created_at)
        VALUES (?,?,?,?,?,?,?,NOW())
    ");

    $stmt->execute([
        $name,
        $mobile,
        $village,
        $state,
        $district,
        $address,
        'farmer'
    ]);

    $farmer_id = $pdo->lastInsertId();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Farmer added successfully",
        "farmer_id"=>$farmer_id
    ]));

    return $response->withHeader('Content-Type','application/json');
});

$app->post('/job/add', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);
    $data = $request->getParsedBody();

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ONLY ADMIN CAN ADD JOB
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can add jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    JOB DATA
    */

    $farmer_id = $data['farmer_id'] ?? '';
    $acres     = $data['acres'] ?? 0;
    $rate      = $data['rate'] ?? 0;
    $total     = $data['total'] ?? 0;
    $paid      = $data['paid'] ?? 0;
    $job_date  = $data['job_date'] ?? '';

    if(empty($farmer_id) || empty($job_date)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Farmer and job date required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    INSERT JOB
    */

    $stmt = $pdo->prepare("
        INSERT INTO jobs
        (farmer_id,acres,rate,total,paid,job_date,created_at)
        VALUES (?,?,?,?,?,?,NOW())
    ");

    $stmt->execute([
        $farmer_id,
        $acres,
        $rate,
        $total,
        $paid,
        $job_date
    ]);

    $job_id = $pdo->lastInsertId();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Job added successfully",
        "job_id"=>$job_id
    ]));

    return $response->withHeader('Content-Type','application/json');
});
$app->get('/jobs', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET JOBS
    */

    $stmt = $pdo->query("
        SELECT 
            j.*,
            u.name,
            u.village
        FROM jobs j
        JOIN users u ON j.farmer_id = u.id
        ORDER BY j.id DESC
    ");

    $jobs = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$jobs
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->post('/job/update-payment', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);
    $data = $request->getParsedBody();

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can update payment"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    JOB DATA
    */

    $job_id = $data['job_id'] ?? '';
    $paid   = $data['paid'] ?? 0;

    if(empty($job_id)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Job ID required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    UPDATE PAYMENT
    */

    $stmt = $pdo->prepare("
        UPDATE jobs
        SET paid=?
        WHERE id=?
    ");

    $stmt->execute([
        $paid,
        $job_id
    ]);

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Payment updated successfully"
    ]));

    return $response->withHeader('Content-Type','application/json');

});


$app->get('/jobs/pending', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view pending payments"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET PENDING PAYMENTS
    */

    $stmt = $pdo->query("
        SELECT 
            j.*,
            u.name,
            u.village
        FROM jobs j
        JOIN users u ON j.farmer_id = u.id
        WHERE j.paid < j.total
        ORDER BY j.job_date DESC
    ");

    $data = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$data
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/jobs/payment-summary', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view payment summary"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET PAYMENT SUMMARY
    */

    $stmt = $pdo->query("
        SELECT 
            SUM(total) as total,
            SUM(paid) as received
        FROM jobs
    ");

    $result = $stmt->fetch();

    $total = $result['total'] ?? 0;
    $received = $result['received'] ?? 0;
    $pending = $total - $received;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total,
            "received" => (float)$received,
            "pending" => (float)$pending
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/jobs/pending-list', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view pending jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET PENDING JOBS
    */

    $stmt = $pdo->query("
        SELECT
            j.id,
            j.total,
            j.paid,
            u.name,
            u.village
        FROM jobs j
        JOIN users u ON j.farmer_id = u.id
        WHERE j.paid < j.total
        ORDER BY j.job_date DESC
    ");

    $jobs = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$jobs
    ]));

    return $response->withHeader('Content-Type','application/json');

});
$app->get('/jobs/search', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can search jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET SEARCH QUERY
    */

    $params = $request->getQueryParams();
    $query = $params['query'] ?? '';

    if(empty($query)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Search query required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    SEARCH JOBS
    */

    $stmt = $pdo->prepare("
        SELECT
            j.id,
            j.acres,
            j.rate,
            j.total,
            j.paid,
            j.job_date,
            u.name,
            u.village
        FROM jobs j
        JOIN users u ON j.farmer_id = u.id
        WHERE LOWER(u.name) LIKE LOWER(?)
        ORDER BY j.job_date DESC
    ");

    $stmt->execute(["%$query%"]);
    $jobs = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$jobs
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/jobs/monthly-summary', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view monthly summary"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET PARAMETERS
    */

    $params = $request->getQueryParams();
    $month = $params['month'] ?? '';
    $year  = $params['year'] ?? '';

    if(empty($month) || empty($year)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Month and year required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET SUMMARY
    */

    $stmt = $pdo->prepare("
        SELECT 
            SUM(total) as total,
            SUM(paid) as received
        FROM jobs
        WHERE MONTH(job_date) = ?
        AND YEAR(job_date) = ?
    ");

    $stmt->execute([$month, $year]);

    $result = $stmt->fetch();

    $total = $result['total'] ?? 0;
    $received = $result['received'] ?? 0;
    $pending = $total - $received;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total,
            "received" => (float)$received,
            "pending" => (float)$pending
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/jobs/yearly-summary', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view yearly summary"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET YEAR
    */

    $params = $request->getQueryParams();
    $year = $params['year'] ?? '';

    if(empty($year)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Year required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET SUMMARY
    */

    $stmt = $pdo->prepare("
        SELECT 
            SUM(total) as total,
            SUM(paid) as received
        FROM jobs
        WHERE YEAR(job_date) = ?
    ");

    $stmt->execute([$year]);

    $result = $stmt->fetch();

    $total = $result['total'] ?? 0;
    $received = $result['received'] ?? 0;
    $pending = $total - $received;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total,
            "received" => (float)$received,
            "pending" => (float)$pending
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/jobs/today-summary', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view today's summary"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET TODAY SUMMARY
    */

    $stmt = $pdo->query("
        SELECT 
            SUM(acres) as acres,
            SUM(total) as earnings
        FROM jobs
        WHERE DATE(job_date) = CURDATE()
    ");

    $result = $stmt->fetch();

    $acres = $result['acres'] ?? 0;
    $earnings = $result['earnings'] ?? 0;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "acres" => (float)$acres,
            "earnings" => (float)$earnings
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});


$app->get('/jobs/total-pending', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view pending amount"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET TOTAL PENDING
    */

    $stmt = $pdo->query("
        SELECT SUM(total - paid) as pending
        FROM jobs
    ");

    $result = $stmt->fetch();

    $pending = $result['pending'] ?? 0;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "pending" => (float)$pending
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/jobs/recent', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view recent jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET RECENT JOBS
    */

    $stmt = $pdo->query("
        SELECT 
            j.id,
            j.acres,
            j.total,
            u.name,
            u.village
        FROM jobs j
        JOIN users u ON j.farmer_id = u.id
        ORDER BY j.job_date DESC
        LIMIT 5
    ");

    $jobs = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$jobs
    ]));

    return $response->withHeader('Content-Type','application/json');

});


$app->post('/job/update', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);
    $data = $request->getParsedBody();

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can update jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    JOB DATA
    */

    $job_id   = $data['job_id'] ?? '';
    $farmer_id = $data['farmer_id'] ?? '';
    $acres     = $data['acres'] ?? 0;
    $rate      = $data['rate'] ?? 0;
    $total     = $data['total'] ?? 0;
    $job_date  = $data['job_date'] ?? '';

    if(empty($job_id)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Job ID required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    UPDATE JOB
    */

    $stmt = $pdo->prepare("
        UPDATE jobs
        SET farmer_id=?, acres=?, rate=?, total=?, job_date=?
        WHERE id=?
    ");

    $stmt->execute([
        $farmer_id,
        $acres,
        $rate,
        $total,
        $job_date,
        $job_id
    ]);

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Job updated successfully"
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->post('/job/delete', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);
    $data = $request->getParsedBody();

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can delete jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    JOB DATA
    */

    $job_id = $data['job_id'] ?? '';

    if(empty($job_id)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Job ID required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    DELETE JOB
    */

    $stmt = $pdo->prepare("DELETE FROM jobs WHERE id=?");
    $stmt->execute([$job_id]);

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Job deleted successfully"
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/farmer/jobs', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view farmer jobs"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET FARMER ID
    */

    $params = $request->getQueryParams();
    $farmer_id = $params['farmer_id'] ?? '';

    if(empty($farmer_id)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Farmer ID required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET FARMER JOBS
    */

    $stmt = $pdo->prepare("
        SELECT *
        FROM jobs
        WHERE farmer_id = ?
        ORDER BY job_date DESC
    ");

    $stmt->execute([$farmer_id]);
    $jobs = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$jobs
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/farmer/summary', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view farmer summary"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET FARMER ID
    */

    $params = $request->getQueryParams();
    $farmer_id = $params['farmer_id'] ?? '';

    if(empty($farmer_id)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Farmer ID required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET SUMMARY
    */

    $stmt = $pdo->prepare("
        SELECT 
            SUM(acres) as acres,
            SUM(total) as total,
            SUM(paid) as paid
        FROM jobs
        WHERE farmer_id = ?
    ");

    $stmt->execute([$farmer_id]);
    $result = $stmt->fetch();

    $acres = $result['acres'] ?? 0;
    $total = $result['total'] ?? 0;
    $paid = $result['paid'] ?? 0;
    $pending = $total - $paid;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "acres" => (float)$acres,
            "total" => (float)$total,
            "paid" => (float)$paid,
            "pending" => (float)$pending
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->post('/expense/add', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    $data = $request->getParsedBody();

    // if raw JSON
    if(empty($data)){
        $body = $request->getBody()->getContents();
        $data = json_decode($body, true);
    }

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can add expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    EXPENSE DATA
    */

    $title = $data['title'] ?? '';
    $category = $data['category'] ?? '';
    $amount = $data['amount'] ?? 0;
    $expense_date = $data['expense_date'] ?? '';
    $notes = $data['notes'] ?? '';

    if(empty($title) || $amount === ''){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Title and amount required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    INSERT EXPENSE
    */

    $stmt = $pdo->prepare("
        INSERT INTO expenses
        (title,category,amount,expense_date,notes,created_at)
        VALUES (?,?,?,?,?,NOW())
    ");

    $stmt->execute([
        $title,
        $category,
        $amount,
        $expense_date,
        $notes
    ]);

    $expense_id = $pdo->lastInsertId();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Expense added successfully",
        "expense_id"=>$expense_id
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/expenses', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET EXPENSES
    */

    $stmt = $pdo->query("
        SELECT *
        FROM expenses
        ORDER BY expense_date DESC
    ");

    $expenses = $stmt->fetchAll();

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>$expenses
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/expenses/monthly', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view monthly expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET PARAMETERS
    */

    $params = $request->getQueryParams();
    $month = $params['month'] ?? '';
    $year  = $params['year'] ?? '';

    if(empty($month) || empty($year)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Month and year required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET MONTHLY EXPENSE
    */

    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total
        FROM expenses
        WHERE MONTH(expense_date)=?
        AND YEAR(expense_date)=?
    ");

    $stmt->execute([$month,$year]);

    $result = $stmt->fetch();
    $total = $result['total'] ?? 0;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});


$app->post('/expense/delete', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    $data = $request->getParsedBody();

    /*
    AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can delete expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    EXPENSE DATA
    */

    $expense_id = $data['expense_id'] ?? '';

    if(empty($expense_id)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Expense ID required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    DELETE EXPENSE
    */

    $stmt = $pdo->prepare("DELETE FROM expenses WHERE id=?");
    $stmt->execute([$expense_id]);

    $response->getBody()->write(json_encode([
        "status"=>true,
        "message"=>"Expense deleted successfully"
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/expenses/last-3-months', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET LAST 3 MONTHS EXPENSE
    */

    $stmt = $pdo->query("
        SELECT SUM(amount) as total
        FROM expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
    ");

    $result = $stmt->fetch();
    $total = $result['total'] ?? 0;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

$app->get('/expenses/yearly', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view yearly expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET YEAR
    */

    $params = $request->getQueryParams();
    $year = $params['year'] ?? '';

    if(empty($year)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Year required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET YEARLY EXPENSE
    */

    $stmt = $pdo->prepare("
        SELECT SUM(amount) as total
        FROM expenses
        WHERE YEAR(expense_date)=?
    ");

    $stmt->execute([$year]);

    $result = $stmt->fetch();
    $total = $result['total'] ?? 0;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});


$app->get('/expenses/today', function (Request $request, Response $response) use ($app){

    $pdo = $app->getContainer()->get(PDO::class);

    /*
    GET AUTH TOKEN
    */

    $token = $request->getHeaderLine('Authorization');
    $token = str_replace('Bearer ','',$token);

    if(empty($token)){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Authorization token required"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    VALIDATE SESSION + ROLE
    */

    $stmt = $pdo->prepare("
        SELECT u.id,u.role
        FROM user_devices d
        JOIN users u ON u.id = d.user_id
        WHERE d.session_token=?
    ");

    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if(!$user){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Invalid session"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    ADMIN ONLY
    */

    if($user['role'] != 'admin'){
        $response->getBody()->write(json_encode([
            "status"=>false,
            "message"=>"Only admin can view today's expenses"
        ]));
        return $response->withHeader('Content-Type','application/json');
    }

    /*
    GET TODAY EXPENSE
    */

    $stmt = $pdo->query("
        SELECT SUM(amount) as total
        FROM expenses
        WHERE DATE(expense_date) = CURDATE()
    ");

    $result = $stmt->fetch();
    $total = $result['total'] ?? 0;

    $response->getBody()->write(json_encode([
        "status"=>true,
        "data"=>[
            "total" => (float)$total
        ]
    ]));

    return $response->withHeader('Content-Type','application/json');

});

};
