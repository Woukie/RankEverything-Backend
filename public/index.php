<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

use Monolog\Level;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/vendor/autoload.php';

$dbhost = $_ENV['DB_HOST'];
$dbname = $_ENV['DB_NAME'];
$dbuser = $_ENV['DB_USER'];
$dbpassword = $_ENV['DB_PASSWORD'];

$log = new Logger('all');
$log->pushHandler(new StreamHandler('logs/all.log', Level::Info));

// Create connection
$log->info("Connecting to database '$dbname' on '$dbhost' as user '$dbuser'...");
$conn = new mysqli($dbhost, $dbuser, $dbpassword, $dbname, 3306);

// Check connection
if ($conn->connect_error) {
    $log->error("Connection to database failed: " . $conn->connect_error);
    die("Database is down, can't take traffic!");
}
$log->info("Connected to database successfully");

// Create things table
if (
    $conn->query("CREATE TABLE IF NOT EXISTS Things (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name TINYTEXT NOT NULL,
    image_url TINYTEXT NOT NULL,
    description TINYTEXT NOT NULL,
    votes BIGINT(6) UNSIGNED DEFAULT 0 NOT NULL,
    adult BOOLEAN NOT NULL,
    created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )") === TRUE
) {
    $log->info("Table 'Things' created successfully");
} else {
    $log->error("Error creating table: " . $conn->error);
}

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    global $log;

    $log->info("Serving '/' endpoint");
    $response->getBody()->write("Under construction! This is the Rank Everything backend. It will also probably be the distribution page.");
    $log->info("Served '/' endpoint");

    return $response;
});

$app->get('/get_comparison', function (Request $request, Response $response, $args) {
    global $conn, $log;
    $log->info("Serving '/get_comparison' endpoint");

    // https://stackoverflow.com/a/4329447
    $things = array();
    for ($i = 0; $i < 2; $i++) {
        $result = $conn->query(
            "SELECT * FROM Things AS r1 JOIN
                (SELECT CEIL(RAND() *
                                (SELECT MAX(id)
                                    FROM Things)) AS id)
                    AS r2
            WHERE r1.id >= r2.id
            ORDER BY r1.id ASC
            LIMIT 1"
        );
        $thing = $result->fetch_assoc();
        array_push($things, $thing);
    }

    $json_things = json_encode($things);
    $log->info("Got things from database: $json_things");
    $response->getBody()->write($json_things);
    $log->info("Served '/get_comparison' endpoint");

    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/submit_thing', function (Request $request, Response $response, $args) {
    global $conn, $log;
    $log->info("Serving '/submit_thing' endpoint");

    $params = json_decode($request->getBody(), true);


    $log->info("Recieved body: $params");

    if (
        !(
            array_key_exists('name', $params)
            && array_key_exists('imageUrl', $params)
            && array_key_exists('description', $params)
            && array_key_exists('adult', $params)
        )
    ) {
        die('Must specify all parameters');
    }

    $name = $params['name'];
    $imageUrl = $params['imageUrl'];
    $description = $params['description'];
    $adult = $params['adult'];

    $insert_statement = $conn->prepare("INSERT INTO Things (name, image_url, description, adult) VALUES (?, ?, ?, ?)");
    $insert_statement->bind_param("sssi", $name, $imageUrl, $description, $adult);

    if ($insert_statement->execute() === TRUE) {
        $log->info("New record created successfully");
    } else {
        $log->error("Error submitting thing: " . $conn->error);
    }

    $log->info("Served '/submit_thing' endpoint");

    return $response;
});

$app->get('[/{params:.*}]', function ($request, $response, array $args) {
    $response->getBody()->write("Unknown directory '" . $args['params'] . "'");
    return $response;
});

$app->run();

$conn->close();
