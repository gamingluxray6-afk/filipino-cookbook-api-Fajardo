<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

// 1. Database Connection (Default XAMPP credentials)
$host = '127.0.0.1';
$db   = 'filipino_cookbook_api'; // Database Name
$user = 'root';                  // XAMPP default user
$pass = '';                      // XAMPP default password (leave empty)
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$pdo = new PDO($dsn, $user, $pass, $options);

// 2. Token-Based Security Middleware
$app->add(function (Request $request, $handler) {
    $uri = $request->getUri()->getPath();
       $expectedToken = 'Bearer dmmmsu-cookbook-token-2026';
    // Apply security only to routes starting with /api
    if (strpos($uri, '/api') === 0) {
        $authHeader = $request->getHeaderLine('Authorization');
        $expectedToken = 'Bearer dmmmsu-cookbook-token-2026'; // Required Token
        
        if ($authHeader !== $expectedToken) {
            $response = new \Slim\Psr7\Response();
            $payload = [
                'status' => 'error',
                'message' => 'Unauthorized access. Valid API token is required.'
            ];
            $response->getBody()->write(json_encode($payload));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }
    return $handler->handle($request);
});

// Add Body Parsing Middleware (Required for POST JSON requests)
$app->addBodyParsingMiddleware();

// Helper Function to fetch Food data with Ingredients
function getFoodData($pdo, $foodId = null, $searchName = null) {
    $sql = "SELECT f.food_id, f.food_name, f.instructions, c.category_name, o.origin_name 
            FROM foods f
            JOIN categories c ON f.category_id = c.category_id
            JOIN origins o ON f.origin_id = o.origin_id";
    $params = [];
    
    if ($foodId) {
        $sql .= " WHERE f.food_id = ?";
        $params[] = $foodId;
    } elseif ($searchName) {
        $sql .= " WHERE f.food_name LIKE ?";
        $params[] = "%" . $searchName . "%";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $foods = $stmt->fetchAll();
    
    // Fetch ingredients for each food
    foreach ($foods as &$food) {
        $ingStmt = $pdo->prepare("SELECT i.ingredient_name 
                                  FROM ingredients i
                                  JOIN food_ingredients fi ON i.ingredient_id = fi.ingredient_id
                                  WHERE fi.food_id = ? ORDER BY i.ingredient_name");
        $ingStmt->execute([$food['food_id']]);
        $food['ingredients'] = $ingStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    return $foods;
}

// ================= API ROUTES =================

// 1. Public Welcome Route (No Token Required)
$app->get('/', function (Request $request, Response $response) {
    $payload = [
        'message' => 'Welcome to the Secured Filipino Cookbook API',
        'note' => 'Use a valid Bearer token to access /api endpoints.'
    ];
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});

// 2. Get All Foods
$app->get('/api/foods', function (Request $request, Response $response) use ($pdo) {
    $foods = getFoodData($pdo);
    $response->getBody()->write(json_encode($foods));
    return $response->withHeader('Content-Type', 'application/json');
});

// 3. Get Food by ID
$app->get('/api/foods/{id}', function (Request $request, Response $response, $args) use ($pdo) {
    $foods = getFoodData($pdo, $args['id']);
    if (empty($foods)) {
        $payload = ['status' => 'error', 'message' => 'Food not found'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
    $response->getBody()->write(json_encode($foods[0]));
    return $response->withHeader('Content-Type', 'application/json');
});

// 4. Search Food by Name
$app->get('/api/foods/search/{name}', function (Request $request, Response $response, $args) use ($pdo) {
    $foods = getFoodData($pdo, null, $args['name']);
    $response->getBody()->write(json_encode($foods));
    return $response->withHeader('Content-Type', 'application/json');
});

// 5. Get All Categories
$app->get('/api/categories', function (Request $request, Response $response) use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM categories");
    $response->getBody()->write(json_encode($stmt->fetchAll()));
    return $response->withHeader('Content-Type', 'application/json');
});

// 6. Get All Ingredients
$app->get('/api/ingredients', function (Request $request, Response $response) use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM ingredients");
    $response->getBody()->write(json_encode($stmt->fetchAll()));
    return $response->withHeader('Content-Type', 'application/json');
});

// 7. Add New Food
$app->post('/api/foods', function (Request $request, Response $response) use ($pdo) {
    $data = $request->getParsedBody();
    
    $food_name = $data['food_name'] ?? '';
    $category_id = $data['category_id'] ?? null;
    $origin_id = $data['origin_id'] ?? null;
    $instructions = $data['instructions'] ?? '';
    $ingredient_ids = $data['ingredient_ids'] ?? [];
    
    try {
        $pdo->beginTransaction();
        
        // Insert Food
        $stmt = $pdo->prepare("INSERT INTO foods (food_name, category_id, origin_id, instructions) VALUES (?, ?, ?, ?)");
        $stmt->execute([$food_name, $category_id, $origin_id, $instructions]);
        $food_id = $pdo->lastInsertId();
        
        // Insert Ingredients Relationship
        if (!empty($ingredient_ids)) {
            $ingStmt = $pdo->prepare("INSERT INTO food_ingredients (food_id, ingredient_id) VALUES (?, ?)");
            foreach ($ingredient_ids as $ing_id) {
                $ingStmt->execute([$food_id, $ing_id]);
            }
        }
        
        $pdo->commit();
        
        $payload = ['status' => 'success', 'message' => 'Food added successfully.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    } catch (Exception $e) {
        $pdo->rollBack();
        $payload = ['status' => 'error', 'message' => 'Failed to add food.'];
        $response->getBody()->write(json_encode($payload));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
});

$app->run();