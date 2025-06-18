<?php
session_start();
require 'config.php';

// Redirect if user is not logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

/* ---------------------------
    Project Deletion (Admin Only)
------------------------------ */
if (isset($_GET['deleteProject'])) {
    if ($_SESSION['admin'] == 1) { // Only admin can delete.
        $delID = intval($_GET['deleteProject']);
        try {
            $stmtDel = $pdo->prepare("DELETE FROM tblproject WHERE projectID = ?");
            $stmtDel->execute([$delID]);
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $deleteProjectError = "Error deleting project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
    Add Project Processing
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addProject'])) {
    // Retrieve inputs from the Add Project form.
    $prNumber = trim($_POST['prNumber']);
    $projectDetails = trim($_POST['projectDetails']);
    $remarks = trim($_POST['remarks'] ?? "");   // New remarks field.
    $userID = $_SESSION['userID']; // The creator's user ID.

    if (empty($prNumber) || empty($projectDetails)) {
        $projectError = "Please fill in all required fields.";
    } else {
        try {
            // Insert into tblproject (ensure your table has a column for remarks).
            $stmt = $pdo->prepare("INSERT INTO tblproject (prNumber, projectDetails, userID, remarks) VALUES (?, ?, ?, ?)");
            $stmt->execute([$prNumber, $projectDetails, $userID, $remarks]);
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $projectError = "Error adding project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
    Retrieve Projects (with optional search)
------------------------------ */
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// Modify the SELECT query to join with tbluser and fetch firstname and lastname
if ($search !== "") {
    $stmt = $pdo->prepare("SELECT p.*, u.firstname, u.lastname FROM tblproject p JOIN tbluser u ON p.userID = u.userID WHERE p.projectDetails LIKE ? OR p.prNumber LIKE ? ORDER BY p.createdAt DESC");
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt = $pdo->query("SELECT p.*, u.firstname, u.lastname FROM tblproject p JOIN tbluser u ON p.userID = u.userID ORDER BY p.createdAt DESC");
}
$projects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard - DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css">
  <style>
    /* Modal styling for Add Project Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(0,0,0,0.4);
    }
    .modal-content {
      background-color: #fefefe;
      margin: 10% auto;
      padding: 20px;
      border: 1px solid #888;
      width: 90%;
      max-width: 500px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    .close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }
    .close:hover { color: black; }
    form label {
      display: block;
      margin-top: 10px;
    }
    form input, form textarea {
      width: 100%;
      padding: 8px;
      margin-top: 4px;
      box-sizing: border-box;
    }
    form button {
      margin-top: 15px;
      padding: 10px;
      width: 100%;
      border: none;
      background-color: #0d47a1;
      color: white;
      font-weight: bold;
      border-radius: 4px;
      cursor: pointer;
    }
    /* Table header styling updated to show all columns */
    .table-header-custom, .table-row-custom {
      display: flex;
      background-color: #c62828;
      color: white;
      padding: 12px 20px;
      border-top-left-radius: 8px;
      border-top-right-radius: 8px;
      font-weight: bold;
      margin-top: 20px;
      align-items: center;
    }
    .table-row-custom {
      background-color: #fefefe;
      color: #333;
      border-bottom: 1px solid #eee;
    }
    .header-item, .row-item {
      padding: 0 5px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    /* Adjusted widths */
    .header-item:nth-child(1), .row-item:nth-child(1) { /* PR Number */
      flex: 0 0 100px;
      text-align: center;
    }
    .header-item:nth-child(2), .row-item:nth-child(2) { /* Project Details */
      flex: 1;
    }
    .header-item:nth-child(3), .row-item:nth-child(3) { /* Created By (was User ID) */
      flex: 0 0 120px; /* Adjust width as needed for names */
      text-align: center;
    }
    .header-item:nth-child(4), .row-item:nth-child(4) { /* Date Created */
      flex: 0 0 120px;
      text-align: center;
    }
    .header-item:nth-child(5), .row-item:nth-child(5) { /* Date Edited */
      flex: 0 0 120px;
      text-align: center;
    }
    .header-item:nth-child(6), .row-item:nth-child(6) { /* Remarks */
      flex: 0 0 150px;
    }
    .header-item:nth-child(7), .row-item:nth-child(7) { /* Actions */
      flex: 0 0 120px;
      text-align: center;
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 5px;
    }
    /* Action Button Styles */
    .edit-project-btn, .delete-btn {
      width: 30px;
      height: 30px;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 0;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 16px;
      text-decoration: none;
      color: inherit;
      background-color: transparent;
    }
    .edit-project-btn { background-color: #0D47A1; color: white; }
    .delete-btn { background-color: #C62828; color: white; }
    .back-btn {
      display: inline-block;
      background-color: #0d47a1;
      color: #fff;
      padding: 8px 12px;
      text-decoration: none;
      border-radius: 4px;
      margin: 10px;
    }
  </style>
</head>
<body>
  <div class="header">
    <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
    <div class="header-text">
      <div class="title-left">
        SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
      </div>
    </div>
    <div class="user-menu">
      <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
      <div class="dropdown">
        <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon">
        <div class="dropdown-content">
          <a href="logout.php" id="logoutBtn">Log out</a>
        </div>
      </div>
    </div>
  </div>
  
  <?php if ($_SESSION['admin'] == 1): ?>
  <div class="dashboard-action-btns">
    <button id="goToCreate" type="button" class="create-account-btn green-create-btn">
      <img src="assets/images/Add_Button.png" alt="Add" class="add-btn-icon">
      Create Account
    </button>
    <button id="goToAccounts" type="button" class="manage-account-btn">
      <img src="assets/images/Manage_account_Icon.png" alt="Manage" class="manage-btn-icon">
      Manage Accounts
    </button>
  </div>
  <?php endif; ?>
  
  <div class="dashboard-container">
      <div class="dashboard-search-bar-wrapper">
    <div class="dashboard-search-bar-inner">
      <input type="text" id="searchInput" class="dashboard-search-bar" placeholder="Search by PR Number or Project Details...">
    </div>
  </div>

    
    <div class="search-and-add" id="addProjectSection" style="margin-bottom: 20px;">
      <button class="add-pr-button" id="showAddProjectForm">
        <img src="assets/images/Add_Button.png" alt="Add" class="add-pr-icon">
        Add Project
      </button>
    </div>
    
    <?php
      if (isset($projectError)) {
          echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($projectError) . "</p>";
      }
      if (isset($deleteProjectError)) {
          echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($deleteProjectError) . "</p>";
      }
    ?>
    
    <div class="table-header-custom">
      <div class="header-item">PR Number</div>
      <div class="header-item">Project Details</div>
      <div class="header-item">Created By</div> <div class="header-item">Date Created</div>
      <div class="header-item">Date Edited</div>
      <div class="header-item">Remarks</div>
      <div class="header-item">Actions</div>
    </div>

    <?php foreach ($projects as $project): ?>
      <div class="table-row-custom">
        <div class="row-item"><?php echo htmlspecialchars($project['prNumber']); ?></div>
        <div class="row-item"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
        <div class="row-item"><?php echo htmlspecialchars($project['firstname'] . ' ' . $project['lastname']); ?></div>
        <div class="row-item"><?php echo date("m-d-Y", strtotime($project['createdAt'])); ?></div>
        <div class="row-item"><?php echo date("m-d-Y", strtotime($project['editedAt'])); ?></div>
        <div class="row-item"><?php echo htmlspecialchars($project['remarks'] ?? ""); ?></div>
        <div class="row-item actions">
          <a href="edit_project.php?projectID=<?php echo $project['projectID']; ?>" class="edit-project-btn" title="Edit Project">📝</a>
          <?php if ($_SESSION['admin'] == 1): ?>
            <a href="index.php?deleteProject=<?php echo $project['projectID']; ?>" class="delete-btn"
                onclick="return confirm('Are you sure you want to delete this project?');" title="Delete Project">
              🗑️
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div id="noResults" style="display:none; text-align:center; font-weight:bold;">No results</div>
  
  <div id="addProjectModal" class="modal">
    <div class="modal-content">
      <span class="close" id="addProjectClose">&times;</span>
      <h2>Add Project</h2>
      <form id="addProjectForm" action="index.php" method="post">
        <label for="prNumber">Project Number (PR Number)*</label>
        <input type="text" name="prNumber" id="prNumber" required>
        <label for="projectDetails">Project Details*</label>
        <textarea name="projectDetails" id="projectDetails" rows="4" required></textarea>
        <label for="remarks">Remarks (Optional)</label>
        <textarea name="remarks" id="remarks" rows="2"></textarea>
        <button type="submit" name="addProject">Add Project</button>
      </form>
    </div>
  </div>
  
  <script>
    // Add Project Modal logic
    const addProjectModal = document.getElementById('addProjectModal');
    const addProjectClose = document.getElementById('addProjectClose');
    document.getElementById('showAddProjectForm').addEventListener('click', function() {
      addProjectModal.style.display = 'block';
    });
    addProjectClose.addEventListener('click', function() {
      addProjectModal.style.display = 'none';
    });
    window.addEventListener('click', function(event) {
      if (event.target === addProjectModal) {
        addProjectModal.style.display = 'none';
      }
    });
    
    // Button redirections for Create and Manage Accounts.
    document.getElementById('goToCreate') && (document.getElementById('goToCreate').onclick = function() {
      window.location.href = "create_account.php";
    });
    document.getElementById('goToAccounts') && (document.getElementById('goToAccounts').onclick = function() {
      window.location.href = "manage_accounts.php";
    });

    document.getElementById("searchInput").addEventListener("keyup", function() {
    let query = this.value.toLowerCase().trim();
    let rows = document.querySelectorAll(".table-row-custom");
    let visibleCount = 0;
    
    rows.forEach(row => {
        // Get text content of PR Number (first cell) and Project Details (second cell)
        let prNumber = row.children[0].textContent.toLowerCase();
        let projectDetails = row.children[1].textContent.toLowerCase();
        
        if (prNumber.includes(query) || projectDetails.includes(query)) {
            row.style.display = "flex";
            visibleCount++;
        } else {
            row.style.display = "none";
        }
    });
    
    // Show "No results" message if no rows are visible
    const noResultsDiv = document.getElementById("noResults");
    if (visibleCount === 0) {
        noResultsDiv.style.display = "block";
    } else {
        noResultsDiv.style.display = "none";
    }
    });
  </script>
</body>
</html>