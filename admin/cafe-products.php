<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Check if admin is logged in
if (!isLoggedIn()) {
    header("Location: ../index.php");
    exit;
}

// Initialize variables
$error = '';
$success = '';
$products = [];

// Check if cafe_products table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'cafe_products'");
if ($table_exists->num_rows == 0) {
    // If table doesn't exist, create it
    $conn->query(file_get_contents('cafe_database.sql'));
    $success = "Cafe database tables created successfully.";
}

// Handle product actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_product'])) {
        $name = sanitizeInput($_POST['name']);
        $description = sanitizeInput($_POST['description']);
        $category = sanitizeInput($_POST['category']);
        $price = (float)$_POST['price'];
        $cost_price = (float)$_POST['cost_price'];
        $stock_quantity = (int)$_POST['stock_quantity'];
        $status = sanitizeInput($_POST['status']);
        
        if (empty($name) || $price <= 0 || $cost_price <= 0) {
            $error = "Please fill in all required fields with valid values.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO cafe_products (name, description, category, price, cost_price, stock_quantity, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("sssddis", $name, $description, $category, $price, $cost_price, $stock_quantity, $status);
                
                if ($stmt->execute()) {
                    $success = "Product added successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Added new cafe product: $name");
                } else {
                    $error = "Failed to add product";
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_product'])) {
        $id = $_POST['product_id'];
        $name = sanitizeInput($_POST['name']);
        $description = sanitizeInput($_POST['description']);
        $category = sanitizeInput($_POST['category']);
        $price = (float)$_POST['price'];
        $cost_price = (float)$_POST['cost_price'];
        $stock_quantity = (int)$_POST['stock_quantity'];
        $status = sanitizeInput($_POST['status']);
        
        if (empty($name) || $price <= 0 || $cost_price <= 0) {
            $error = "Please fill in all required fields with valid values.";
        } else {
            try {
                $stmt = $conn->prepare("UPDATE cafe_products SET name = ?, description = ?, category = ?, price = ?, cost_price = ?, stock_quantity = ?, status = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("sssddisi", $name, $description, $category, $price, $cost_price, $stock_quantity, $status, $id);
                
                if ($stmt->execute()) {
                    $success = "Product updated successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Updated cafe product #$id: $name");
                } else {
                    $error = "Failed to update product";
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "System error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_product'])) {
        $id = $_POST['product_id'];
        
        try {
            // Check if product is used in any orders
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM cafe_order_items WHERE product_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            $stmt->close();
            
            if ($count > 0) {
                $error = "Cannot delete this product as it is used in $count order(s).";
            } else {
                $stmt = $conn->prepare("DELETE FROM cafe_products WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Database error: " . $conn->error);
                }
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    $success = "Product deleted successfully";
                    logActivity($conn, $_SESSION['admin_id'], "Deleted cafe product #$id");
                } else {
                    $error = "Failed to delete product";
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_stock'])) {
        $id = $_POST['product_id'];
        $stock_quantity = (int)$_POST['stock_quantity'];
        
        try {
            $stmt = $conn->prepare("UPDATE cafe_products SET stock_quantity = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception("Database error: " . $conn->error);
            }
            $stmt->bind_param("ii", $stock_quantity, $id);
            
            if ($stmt->execute()) {
                $success = "Stock updated successfully";
                logActivity($conn, $_SESSION['admin_id'], "Updated stock for cafe product #$id");
            } else {
                $error = "Failed to update stock";
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "System error: " . $e->getMessage();
        }
    }
}

// Get all products
try {
    $result = $conn->query("SELECT * FROM cafe_products ORDER BY category, name");
    $products = $result;
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cafe Products - Gym Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include 'sidebar.php'; ?>
            
            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Manage Cafe Products</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="bi bi-plus-circle me-1"></i> Add New Product
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Products List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Cafe Products</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Cost</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (is_object($products) && $products->num_rows > 0): ?>
                                        <?php while ($product = $products->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo $product['id']; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                                    <?php if (!empty($product['description'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($product['description']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php 
                                                        echo $product['category'] === 'food' ? 'bg-success' : 
                                                            ($product['category'] === 'beverage' ? 'bg-info' : 
                                                            ($product['category'] === 'supplement' ? 'bg-warning' : 'bg-secondary')); 
                                                    ?>">
                                                        <?php echo ucfirst($product['category']); ?>
                                                    </span>
                                                </td>
                                                <td>Rs <?php echo number_format($product['price'], 2); ?></td>
                                                <td>Rs <?php echo number_format($product['cost_price'], 2); ?></td>
                                                <td>
                                                    <span class="badge <?php echo $product['stock_quantity'] <= 5 ? 'bg-danger' : ($product['stock_quantity'] <= 20 ? 'bg-warning' : 'bg-success'); ?>">
                                                        <?php echo $product['stock_quantity']; ?>
                                                    </span>
                                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#updateStockModal<?php echo $product['id']; ?>">
                                                        <i class="bi bi-arrow-repeat"></i>
                                                    </button>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $product['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo ucfirst($product['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editProductModal<?php echo $product['id']; ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteProductModal<?php echo $product['id']; ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    
                                                    <!-- Update Stock Modal -->
                                                    <div class="modal fade" id="updateStockModal<?php echo $product['id']; ?>" tabindex="-1" aria-labelledby="updateStockModalLabel<?php echo $product['id']; ?>" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="updateStockModalLabel<?php echo $product['id']; ?>">Update Stock</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <form method="POST" action="">
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="stock_quantity<?php echo $product['id']; ?>" class="form-label">Stock Quantity</label>
                                                                            <input type="number" class="form-control" id="stock_quantity<?php echo $product['id']; ?>" name="stock_quantity" value="<?php echo $product['stock_quantity']; ?>" required min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" name="update_stock" class="btn btn-primary">Update Stock</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Edit Product Modal -->
                                                    <div class="modal fade" id="editProductModal<?php echo $product['id']; ?>" tabindex="-1" aria-labelledby="editProductModalLabel<?php echo $product['id']; ?>" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="editProductModalLabel<?php echo $product['id']; ?>">Edit Product</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <form method="POST" action="">
                                                                    <div class="modal-body">
                                                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="name<?php echo $product['id']; ?>" class="form-label">Product Name</label>
                                                                            <input type="text" class="form-control" id="name<?php echo $product['id']; ?>" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="description<?php echo $product['id']; ?>" class="form-label">Description</label>
                                                                            <textarea class="form-control" id="description<?php echo $product['id']; ?>" name="description" rows="3"><?php echo htmlspecialchars($product['description']); ?></textarea>
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="category<?php echo $product['id']; ?>" class="form-label">Category</label>
                                                                            <select class="form-select" id="category<?php echo $product['id']; ?>" name="category" required>
                                                                                <option value="food" <?php echo $product['category'] === 'food' ? 'selected' : ''; ?>>Food</option>
                                                                                <option value="beverage" <?php echo $product['category'] === 'beverage' ? 'selected' : ''; ?>>Beverage</option>
                                                                                <option value="supplement" <?php echo $product['category'] === 'supplement' ? 'selected' : ''; ?>>Supplement</option>
                                                                                <option value="other" <?php echo $product['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                                                            </select>
                                                                        </div>
                                                                        
                                                                        <div class="row mb-3">
                                                                            <div class="col-md-6">
                                                                                <label for="price<?php echo $product['id']; ?>" class="form-label">Price (Rs)</label>
                                                                                <input type="number" class="form-control" id="price<?php echo $product['id']; ?>" name="price" value="<?php echo $product['price']; ?>" required min="0" step="0.01" placeholder="Rs 0.00">
                                                                            </div>
                                                                            <div class="col-md-6">
                                                                                <label for="cost_price<?php echo $product['id']; ?>" class="form-label">Cost Price (Rs)</label>
                                                                                <input type="number" class="form-control" id="cost_price<?php echo $product['id']; ?>" name="cost_price" value="<?php echo $product['cost_price']; ?>" required min="0" step="0.01" placeholder="Rs 0.00">
                                                                            </div>
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="stock_quantity<?php echo $product['id']; ?>_edit" class="form-label">Stock Quantity</label>
                                                                            <input type="number" class="form-control" id="stock_quantity<?php echo $product['id']; ?>_edit" name="stock_quantity" value="<?php echo $product['stock_quantity']; ?>" required min="0">
                                                                        </div>
                                                                        
                                                                        <div class="mb-3">
                                                                            <label for="status<?php echo $product['id']; ?>" class="form-label">Status</label>
                                                                            <select class="form-select" id="status<?php echo $product['id']; ?>" name="status" required>
                                                                                <option value="active" <?php echo $product['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                                                <option value="inactive" <?php echo $product['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                        <button type="submit" name="update_product" class="btn btn-primary">Update Product</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Delete Product Modal -->
                                                    <div class="modal fade" id="deleteProductModal<?php echo $product['id']; ?>" tabindex="-1" aria-labelledby="deleteProductModalLabel<?php echo $product['id']; ?>" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title" id="deleteProductModalLabel<?php echo $product['id']; ?>">Confirm Delete</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <div class="modal-body">
                                                                    Are you sure you want to delete the product "<?php echo htmlspecialchars($product['name']); ?>"?
                                                                    <p class="text-danger mt-2">This action cannot be undone. If this product has been used in orders, you will not be able to delete it.</p>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <form method="POST" action="">
                                                                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                                                                        <button type="submit" name="delete_product" class="btn btn-danger">Delete</button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No products found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Add Product Modal -->
                <div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addProductModalLabel">Add New Product</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Product Name</label>
                                        <input type="text" class="form-control" id="name" name="name" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="description" class="form-label">Description</label>
                                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="category" class="form-label">Category</label>
                                        <select class="form-select" id="category" name="category" required>
                                            <option value="">Select Category</option>
                                            <option value="food">Food</option>
                                            <option value="beverage">Beverage</option>
                                            <option value="supplement">Supplement</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="price" class="form-label">Price (Rs)</label>
                                            <input type="number" class="form-control" id="price" name="price" required min="0" step="0.01" placeholder="Rs 0.00">
                                        </div>
                                        <div class="col-md-6">
                                            <label for="cost_price" class="form-label">Cost Price (Rs)</label>
                                            <input type="number" class="form-control" id="cost_price" name="cost_price" required min="0" step="0.01" placeholder="Rs 0.00">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="stock_quantity" class="form-label">Stock Quantity</label>
                                        <input type="number" class="form-control" id="stock_quantity" name="stock_quantity" required min="0" value="0">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="active" selected>Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="add_product" class="btn btn-primary">Add Product</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>
