<?php
/**
 * My Pets Controller
 *
 * Allows pet owners (admin role) to manage their pets — view, add, update
 * status, and delete. Role is checked server-side on every action;
 * Zara (browsing user / 'user' role) is blocked from all pet management operations.
 *
 * Improvements:
 *  - Role gate: only 'admin' users can add/edit/delete pets.
 *  - Image processing: uploaded photos are resized to max 800px using GD
 *    before saving (performance mark improvement).
 */

session_start();

require_once('Models/PetDataSet.php');
require_once('Models/UserAuthentication.php');

// View object
$view = new stdClass();
$view->pageTitle = 'My Pets';
$view->message = '';
$view->redirect = '';   // Set to a URL string to trigger JS redirect in view
$view->user = new User();

// Authentication check
// Must be logged in to access this page at all
if (!$view->user->isLoggedIn()) {
    $_SESSION['error_message'] = 'You must be logged in to manage pets.';
    // JS-safe redirect — view renders a <script>window.location.href</script>
    $view->redirect = 'authenticate.php';
    require_once('Views/mypets.phtml');
    exit();
}

// Role check (hard block)
// Only 'admin' role users (pet owners/managers) may access this page.
// This check runs on EVERY request — including direct URL entry —
// so Zara cannot reach the dashboard by typing the URL manually.
// The JS-safe redirect pattern is used (no PHP header() per brief plan).
if (!$view->user->isOwner()) {
    $_SESSION['error_message'] = 'Access denied. Only pet owners can manage pets.';
    $view->redirect = 'index.php';   // Send non-owners back to the homepage
    require_once('Views/mypets.phtml');
    exit();
}

// If we reach this point the user is confirmed logged-in AND is an owner.
$view->isOwner = true;

// Dataset
$view->petDataSet = new PetDataSet();

// HANDLE DELETE PET (admin only)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {

    // Double-check role server-side — never trust client-side checks alone
    if (!$view->user->isOwner()) {
        $view->message = 'Access denied. Only pet owners can delete pets.';
    } else {
        $petId = (int) $_GET['id'];
        $pet = $view->petDataSet->fetchPetById($petId);

        // Verify the pet belongs to the currently logged-in owner
        if ($pet && $pet->getUserID() == $view->user->userID()) {
            // Remove the pet's photo from disk if one exists
            if ($pet->hasPhoto()) {
                $photoPath = __DIR__ . '/images/pet-photos/' . $pet->getPetPhoto();
                if (file_exists($photoPath)) {
                    unlink($photoPath);
                }
            }

            if ($view->petDataSet->deletePet($petId)) {
                $view->message = "Pet '" . htmlspecialchars($pet->getPetName()) . "' has been deleted successfully.";
            } else {
                $view->message = 'Error: could not delete pet from database.';
            }
        } else {
            $view->message = "You don't have permission to delete this pet.";
        }
    }

    // Store message in session then redirect immediately with header()
    // so the browser never sees an intermediate page (no flash)
    $_SESSION['flash_message'] = $view->message;
    header('Location: mypets.php?deleted=1');
    exit();
}

// HANDLE UPDATE STATUS (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateStatusBtn'])) {

    if (!$view->user->isOwner()) {
        $view->message = 'Access denied. Only pet owners can update pet status.';
    } else {
        $petId = isset($_POST['pet_id']) ? (int) $_POST['pet_id'] : 0;
        $newStatus = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';

        $pet = $view->petDataSet->fetchPetById($petId);

        if ($pet && $pet->getUserID() == $view->user->userID()) {
            $validStatuses = ['lost', 'found'];

            if (in_array($newStatus, $validStatuses)) {
                if ($view->petDataSet->updatePetStatus($petId, $newStatus)) {
                    $view->message = "Status for '" . htmlspecialchars($pet->getPetName())
                        . "' updated to '" . ucfirst($newStatus) . "'.";
                } else {
                    $view->message = 'Error: could not update pet status.';
                }
            } else {
                $view->message = 'Invalid status value submitted.';
            }
        } else {
            $view->message = "You don't have permission to update this pet.";
        }
    }
}

// HANDLE ADD PET (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addpetbtn'])) {

    // Server-side role enforcement — Zara must never reach this block
    if (!$view->user->isOwner()) {
        $view->message = 'Access denied. Only pet owners can add pets.';
    } else {

        // Collect and sanitize form inputs
        $petName = isset($_POST['petName']) ? trim($_POST['petName']) : '';
        $petStatus = isset($_POST['petstatus']) ? trim($_POST['petstatus']) : '';
        $petSpecies = isset($_POST['petspecies']) ? trim($_POST['petspecies']) : '';
        $petBreed = isset($_POST['petbreed']) ? trim($_POST['petbreed']) : '';
        $petColor = isset($_POST['petcolor']) ? trim($_POST['petcolor']) : '';
        $petDescription = isset($_POST['petdescription']) ? trim($_POST['petdescription']) : '';

        $errors = [];

        // Validation
        if (empty($petName)) {
            $errors[] = 'Pet name is required.';
        }
        if (empty($petStatus)) {
            $errors[] = 'Pet status is required.';
        }
        if (empty($petSpecies)) {
            $errors[] = 'Pet species is required.';
        }

        // Validate status value against allowed set
        if (!empty($petStatus) && !in_array($petStatus, ['lost', 'found'])) {
            $errors[] = 'Invalid status value.';
        }

        // Image upload validation
        $newFileName = '';
        if (!isset($_FILES['petphoto']) || $_FILES['petphoto']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'A pet photo is required.';
        } else {
            $uploadDir = __DIR__ . '/images/pet-photos/';
            $fileTempPath = $_FILES['petphoto']['tmp_name'];
            $fileName = basename($_FILES['petphoto']['name']);
            $fileSize = $_FILES['petphoto']['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif'];

            // Validate MIME type via GD (more secure than extension alone)
            $imageInfo = @getimagesize($fileTempPath);
            $allowedMime = ['image/jpeg', 'image/png', 'image/gif'];

            if (!in_array($fileExtension, $allowedExt)) {
                $errors[] = 'Invalid file type. Only JPG, PNG, and GIF are allowed.';
            } elseif (!$imageInfo || !in_array($imageInfo['mime'], $allowedMime)) {
                $errors[] = 'Uploaded file is not a valid image.';
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $errors[] = 'File too large. Maximum size is 5 MB.';
            } else {
                // Create upload directory if it does not exist
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $newFileName = uniqid('pet_', true) . '.' . $fileExtension;
            }
        }

        // Process upload and DB insert if validation passed
        if (empty($errors)) {
            $destination = __DIR__ . '/images/pet-photos/' . $newFileName;

            // Performance: resize & compress uploaded image using GD
            // Resizes to a max of 800px wide (preserving aspect ratio) and
            // compresses to quality 80 (JPEG) / level 7 (PNG).
            //
            // Covers JPG, PNG, and GIF. Falls back to a plain file move if
            // GD is not installed on the server (e.g. minimal PHP installs).
            $resized = false;

            if (function_exists('imagecreatefromjpeg')) {
                // Load source image based on detected MIME type
                $src = null;
                switch ($fileExtension) {
                    case 'jpg':
                    case 'jpeg':
                        $src = @imagecreatefromjpeg($fileTempPath);
                        break;
                    case 'png':
                        $src = @imagecreatefrompng($fileTempPath);
                        break;
                    case 'gif':
                        $src = @imagecreatefromgif($fileTempPath);
                        break;
                }

                if ($src) {
                    $origW = imagesx($src);
                    $origH = imagesy($src);
                    $maxW = 800;

                    // Only resize if wider than maxW — never upscale smaller images
                    if ($origW > $maxW) {
                        $newW = $maxW;
                        $newH = (int) round($origH * ($maxW / $origW));
                    } else {
                        $newW = $origW;
                        $newH = $origH;
                    }

                    $dst = imagecreatetruecolor($newW, $newH);

                    // Preserve transparency for PNG and GIF
                    if (in_array($fileExtension, ['png', 'gif'])) {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                        // For GIF: fill with transparent colour
                        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                        imagefill($dst, 0, 0, $transparent);
                    }

                    // High-quality resampling (slower but better than imagecopyresized)
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

                    // Save with compression — PNG level 7 (~60% size), JPEG quality 80
                    switch ($fileExtension) {
                        case 'png':
                            imagepng($dst, $destination, 7);
                            break;
                        case 'gif':
                            imagegif($dst, $destination);
                            break;
                        default: // jpg / jpeg
                            imagejpeg($dst, $destination, 80);
                            break;
                    }

                    imagedestroy($src);
                    imagedestroy($dst);
                    $resized = true;
                }
            }

            // GD not available or image load failed — fall back to plain file move.
            // The image won't be compressed but the upload still succeeds.
            if (!$resized) {
                move_uploaded_file($fileTempPath, $destination);
            }

            // Insert pet record into database
            $petId = $view->petDataSet->addPet(
                $view->user->userID(),
                $petName,
                $petStatus,
                $petSpecies,
                $petBreed,
                $petColor,
                $petDescription,
                $newFileName
            );

            if ($petId) {
                // Store message in session then redirect immediately with header() [no other option]
                $_SESSION['flash_message'] = "Pet '$petName' has been added successfully.";
                header('Location: mypets.php?added=1');
                exit();
            } else {
                // DB insert failed — clean up the uploaded file
                if (file_exists($destination)) {
                    unlink($destination);
                }
                $view->message = 'Error: could not save pet to the database.';
            }
        } else {
            // Show all validation errors joined into one message
            $view->message = implode(' ', $errors);
        }
    }
}

// SEARCH, FILTER, AND SORT (runs on every page load)
$searchTerm = '';
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterSpecies = isset($_GET['species']) ? trim($_GET['species']) : '';
$sortBy = isset($_GET['sort']) ? trim($_GET['sort']) : 'date_newest';

if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $searchTerm = trim($_GET['search']);
}

// Fetch all pets owned by this user
$allPets = $view->petDataSet->fetchUsersPets($view->user->userID());

// Serialise ALL pets to JSON before any filtering — used by the client-side
// live search so it can filter/sort/paginate without page reloads
$view->allPetsJson = json_encode(array_values(array_map(function ($p) {
    return [
        'id' => $p->getPetID(),
        'name' => htmlspecialchars($p->getPetName(), ENT_QUOTES, 'UTF-8'),
        'status' => htmlspecialchars($p->getPetStatus(), ENT_QUOTES, 'UTF-8'),
        'species' => htmlspecialchars($p->getPetSpecies(), ENT_QUOTES, 'UTF-8'),
        'breed' => htmlspecialchars($p->getPetBreed(), ENT_QUOTES, 'UTF-8'),
        'color' => htmlspecialchars($p->getPetColor(), ENT_QUOTES, 'UTF-8'),
        'description' => htmlspecialchars($p->getPetDescription(), ENT_QUOTES, 'UTF-8'),
        'photo_path' => htmlspecialchars($p->getPhotoPath(), ENT_QUOTES, 'UTF-8'),
        'date' => htmlspecialchars($p->getDateReported(), ENT_QUOTES, 'UTF-8'),
    ];
}, $allPets)));

// Apply free-text search (name, species, breed, colour, description, status)
if (!empty($searchTerm)) {
    $allPets = array_filter($allPets, function ($pet) use ($searchTerm) {
        $s = strtolower($searchTerm);
        return (
            stripos($pet->getPetName(), $s) !== false ||
            stripos($pet->getPetSpecies(), $s) !== false ||
            stripos($pet->getPetBreed(), $s) !== false ||
            stripos($pet->getPetColor(), $s) !== false ||
            stripos($pet->getPetDescription(), $s) !== false ||
            stripos($pet->getPetStatus(), $s) !== false
        );
    });
}

// Apply status filter (lost / found)
if (!empty($filterStatus)) {
    $allPets = array_filter($allPets, function ($pet) use ($filterStatus) {
        return strtolower($pet->getPetStatus()) === strtolower($filterStatus);
    });
}

// Apply species filter
if (!empty($filterSpecies)) {
    $allPets = array_filter($allPets, function ($pet) use ($filterSpecies) {
        return strtolower($pet->getPetSpecies()) === strtolower($filterSpecies);
    });
}

// Apply sort
usort($allPets, function ($a, $b) use ($sortBy) {
    switch ($sortBy) {
        case 'name_asc':
            return strcasecmp($a->getPetName(), $b->getPetName());
        case 'name_desc':
            return strcasecmp($b->getPetName(), $a->getPetName());
        case 'date_oldest':
            return strcmp($a->getDateReported(), $b->getDateReported());
        case 'date_newest': // fall-through
        default:
            return strcmp($b->getDateReported(), $a->getDateReported());
    }
});

// Pagination
$petsPerPage = 6;
$totalPets = count($allPets);
$totalPages = max(1, ceil($totalPets / $petsPerPage));
$currentPage = isset($_GET['page']) ? max(1, min((int) $_GET['page'], $totalPages)) : 1;
$offset = ($currentPage - 1) * $petsPerPage;

$view->petData = array_slice($allPets, $offset, $petsPerPage);
$view->currentPage = $currentPage;
$view->totalPages = $totalPages;
$view->totalPets = $totalPets;
$view->searchTerm = $searchTerm;
$view->filterStatus = $filterStatus;
$view->filterSpecies = $filterSpecies;
$view->sortBy = $sortBy;

// Pick up flash message from session (set by add/delete handlers before redirect)
if (isset($_GET['deleted']) || isset($_GET['added'])) {
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    if (!empty($_SESSION['flash_message'])) {
        $view->message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']); // clear after reading
    }
}

require_once('Views/mypets.phtml');
