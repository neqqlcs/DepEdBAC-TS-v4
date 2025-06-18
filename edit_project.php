<?php
session_start();
require 'config.php';

// Check that the user is logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get the projectID from GET parameters.
$projectID = isset($_GET['projectID']) ? intval($_GET['projectID']) : 0;
if ($projectID <= 0) {
    die("Invalid Project ID");
}

// Fetch project details along with creator data.
$stmt = $pdo->prepare("SELECT p.*, u.firstname, u.lastname, o.officename 
                       FROM tblproject p 
                       LEFT JOIN tbluser u ON p.userID = u.userID 
                       LEFT JOIN officeid o ON u.officeID = o.officeID 
                       WHERE p.projectID = ?");
$stmt->execute([$projectID]);
$project = $stmt->fetch();
if (!$project) {
    die("Project not found");
}

// Retrieve stages for the project.
$stmt2 = $pdo->prepare("SELECT * FROM tblproject_stages 
                         WHERE projectID = ? 
                         ORDER BY FIELD(stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed')");
$stmt2->execute([$projectID]);
$stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// Define the ordered list of stages.
$stagesOrder = [
    'Purchase Request',
    'RFQ 1',
    'RFQ 2',
    'RFQ 3',
    'Abstract of Quotation',
    'Purchase Order',
    'Notice of Award',
    'Notice to Proceed'
];
if (empty($stages)) {
    // Create records for every stage if none exist.
    foreach ($stagesOrder as $stageName) {
         $stmtInsert = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, office) VALUES (?, ?, ?)");
         $stmtInsert->execute([$projectID, $stageName, ""]);
    }
    $stmt2->execute([$projectID]);
    $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}
// Map stages by stageName.
$stagesMap = [];
foreach ($stages as $s) {
    $stagesMap[$s['stageName']] = $s;
}

// Process Project Header update (available only for admins).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_header'])) {
    if ($_SESSION['admin'] == 1) {
        $prNumber = trim($_POST['prNumber']);
        $projectDetails = trim($_POST['projectDetails']);
        if (empty($prNumber) || empty($projectDetails)) {
             $errorHeader = "PR Number and Project Details are required.";
        } else {
             $stmtUpdate = $pdo->prepare("UPDATE tblproject 
                                          SET prNumber = ?, projectDetails = ?, editedAt = CURRENT_TIMESTAMP, editedBy = ?, 
                                              lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ? 
                                          WHERE projectID = ?");
             $stmtUpdate->execute([$prNumber, $projectDetails, $_SESSION['userID'], $_SESSION['userID'], $projectID]);
             $successHeader = "Project updated successfully.";
             // Reload the updated project details.
             $stmt->execute([$projectID]);
             $project = $stmt->fetch();
        }
    }
}

// Process individual stage submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_stage'])) {
    $stageName = $_POST['stageName'];
    $safeStage = str_replace(' ', '_', $stageName);
    
    // Retrieve new inputs from datetime-local fields.
    $created = isset($_POST["created_$safeStage"]) ? $_POST["created_$safeStage"] : "";
    $approvedAt = isset($_POST['approvedAt']) ? $_POST['approvedAt'] : "";
    $office = isset($_POST["office_$safeStage"]) ? $_POST["office_$safeStage"] : "";
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : "";
    
    // Validate that all fields have values.
    if(empty($created) || empty($approvedAt) || empty($office) || empty($remark)) {
         $stageError = "All fields (Created, Approved, Office, and Remark) are required for stage '$stageName'.";
    } else {
         // Determine if this is a "Submit" (i.e. update stage to submitted) or "Unsubmit" (admin reverting a finished stage).
         $isSubmittedVal = 1;
         if ($_SESSION['admin'] == 1 && isset($stagesMap[$stageName]) && $stagesMap[$stageName]['isSubmitted'] == 1) {
              $isSubmittedVal = 0; // Admin clicked "Unsubmit".
         }
         
         // Convert datetime-local values ("Y-m-d\TH:i") to MySQL datetime ("Y-m-d H:i:s").
         $created_dt = date("Y-m-d H:i:s", strtotime($created));
         $approved_dt = date("Y-m-d H:i:s", strtotime($approvedAt));
         
         $stmtStageUpdate = $pdo->prepare("UPDATE tblproject_stages 
                                      SET createdAt = ?, approvedAt = ?, office = ?, remarks = ?, isSubmitted = ?
                                      WHERE projectID = ? AND stageName = ?");
         $stmtStageUpdate->execute([$created_dt, $approved_dt, $office, $remark, $isSubmittedVal, $projectID, $stageName]);
         $stageSuccess = "Stage '$stageName' updated successfully.";
         
         // If this is a "Submit" action (isSubmittedVal == 1), auto-update the next stage's createdAt if empty.
         if($isSubmittedVal == 1) {
              $index = array_search($stageName, $stagesOrder);
              if($index !== false && $index < count($stagesOrder) - 1) {
                  $nextStage = $stagesOrder[$index + 1];
                  if (!(isset($stagesMap[$nextStage]) && !empty($stagesMap[$nextStage]['createdAt']))) {
                      $now = date("Y-m-d H:i:s");
                      $stmtNext = $pdo->prepare("UPDATE tblproject_stages SET createdAt = ? WHERE projectID = ? AND stageName = ?");
                      $stmtNext->execute([$now, $projectID, $nextStage]);
                  }
              }
         }
         
         // Refresh stage records.
         $stmt2->execute([$projectID]);
         $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
         foreach ($stages as $s) {
              $stagesMap[$s['stageName']] = $s;
         }
         // Update project's last accessed fields.
         $pdo->prepare("UPDATE tblproject SET lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ? WHERE projectID = ?")
             ->execute([$_SESSION['userID'], $projectID]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Project</title>
  <link rel="stylesheet" href="assets/css/home.css">
  <style>
    /* Project Header Styling */
    .project-header {
      margin-bottom: 20px;
      border: 1px solid #ccc;
      padding: 10px;
      border-radius: 8px;
      background: #f9f9f9;
    }
    .project-header label {
      display: block;
      margin-top: 10px;
      font-weight: bold;
    }
    .project-header input, .project-header textarea {
      width: 100%;
      padding: 8px;
      margin-top: 5px;
      border: 1px solid #ccc;
      border-radius: 4px;
    }
    /* Read-only field styling */
    .readonly-field {
      padding: 8px;
      margin-top: 5px;
      border: 1px solid #eee;
      background: #f1f1f1;
    }
    /* Back Button */
    .back-btn {
      display: inline-block;
      background-color: #0d47a1;
      color: white;
      padding: 10px 20px;
      margin-bottom: 20px;
      text-decoration: none;
      border-radius: 4px;
      font-weight: bold;
    }
    /* Stages Table Styling */
    table#stagesTable {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    table#stagesTable th, table#stagesTable td {
      padding: 10px;
      border: 1px solid #ddd;
      text-align: center;
    }
    table#stagesTable th {
      background-color: #c62828;
      color: white;
    }
    table#stagesTable td input {
      width: 90%;
      padding: 4px;
      box-sizing: border-box;
    }
    /* Form for each stage row */
    form.stage-form {
      display: inline;
      margin: 0;
      padding: 0;
    }
  </style>
</head>
<body>
  <div class="dashboard-container">
    <!-- Back Button -->
    <a href="index.php" class="back-btn">&larr; Back</a>
    
    <h2>Edit Project</h2>
    
    <!-- Display Header Messages -->
    <?php 
      if (isset($errorHeader)) { echo "<p style='color:red;'>$errorHeader</p>"; } 
      if (isset($successHeader)) { echo "<p style='color:green;'>$successHeader</p>"; } 
      if (isset($stageError)) { echo "<p style='color:red;'>$stageError</p>"; }
    ?>
    
    <!-- Project Header Section -->
    <div class="project-header">
         <label for="prNumber">PR Number:</label>
         <?php if ($_SESSION['admin'] == 1): ?>
         <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" style="margin-bottom:10px;">
           <input type="text" name="prNumber" id="prNumber" value="<?php echo htmlspecialchars($project['prNumber']); ?>" required>
         <?php else: ?>
           <div class="readonly-field"><?php echo htmlspecialchars($project['prNumber']); ?></div>
         <?php endif; ?>
         
         <label for="projectDetails">Project Details:</label>
         <?php if ($_SESSION['admin'] == 1): ?>
           <textarea name="projectDetails" id="projectDetails" required><?php echo htmlspecialchars($project['projectDetails']); ?></textarea>
         <?php else: ?>
           <div class="readonly-field"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
         <?php endif; ?>
         
         <label>User Info:</label>
         <p><?php echo htmlspecialchars($project['userID'] . " - " . $project['firstname'] . " " . $project['lastname'] . " | Office: " . $project['officename']); ?></p>
         
         <label>Date Created:</label>
         <p><?php echo date("m-d-Y h:i A", strtotime($project['createdAt'])); ?></p>
         
         <label>Date Last Edited:</label>
         <?php 
         $lastEdited = "Not Available";
         if ($project['lastAccessedAt'] && $project['lastAccessedBy']) {
           $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
           $stmtUser->execute([$project['lastAccessedBy']]);
           $lastUser = $stmtUser->fetch();
           if ($lastUser) {
              $lastEdited = $lastUser['firstname'] . " " . $lastUser['lastname'] . ", accessed on " . date("m-d-Y h:i A", strtotime($project['lastAccessedAt']));
           }
         }
         ?>
         <p><?php echo htmlspecialchars($lastEdited); ?></p>
         <?php if ($_SESSION['admin'] == 1): ?>
           <button type="submit" name="update_project_header">Update Project Details</button>
         </form>
         <?php endif; ?>
    </div>
    
    <h3>Project Stages</h3>
    <?php if (isset($stageSuccess)) { echo "<p style='color:green;'>$stageSuccess</p>"; } ?>
    <table id="stagesTable">
       <thead>
          <tr>
            <th>Stage</th>
            <th>Created</th>
            <th>Approved</th>
            <th>Office</th>
            <th>Remark</th>
            <th>Action</th>
          </tr>
       </thead>
       <tbody>
         <?php 
         // Loop through each stage.
         foreach ($stagesOrder as $index => $stage): 
             $safeStage = str_replace(' ', '_', $stage);
             // Check if this stage was submitted.
             $currentSubmitted = (isset($stagesMap[$stage]) && $stagesMap[$stage]['isSubmitted'] == 1);
             // For datetime-local, format as "Y-m-d\TH:i"
             $value_created = ($currentSubmitted && !empty($stagesMap[$stage]['createdAt']))
                     ? date("Y-m-d\TH:i", strtotime($stagesMap[$stage]['createdAt'])) : "";
             $value_approved = ($currentSubmitted && !empty($stagesMap[$stage]['approvedAt']))
                     ? date("Y-m-d\TH:i", strtotime($stagesMap[$stage]['approvedAt'])) : "";
             $value_office = ($currentSubmitted && !empty($stagesMap[$stage]['office']))
                     ? htmlspecialchars($stagesMap[$stage]['office']) : "";
             $value_remark = ($currentSubmitted && !empty($stagesMap[$stage]['remarks']))
                     ? htmlspecialchars($stagesMap[$stage]['remarks']) : "";
             
             // Determine whether submission is allowed.
             $allowSubmission = false;
             if ($index == 0) {
                $allowSubmission = true;
             } else {
                $prevStage = $stagesOrder[$index - 1];
                if (isset($stagesMap[$prevStage]) && $stagesMap[$prevStage]['isSubmitted'] == 1) {
                   $allowSubmission = true;
                }
             }
             // Disable fields if:
             //    - The stage is not allowed for submission OR
             //    - The stage is finished and the user is not admin.
             $disableFields = (!$allowSubmission) || ($currentSubmitted && $_SESSION['admin'] != 1);
         ?>
         <!-- Wrap each stage row in its own form -->
         <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" class="stage-form">
         <tr data-stage="<?php echo htmlspecialchars($stage); ?>">
            <td><?php echo htmlspecialchars($stage); ?></td>
            <td>
              <input type="datetime-local" name="created_<?php echo $safeStage; ?>" value="<?php echo $value_created; ?>" <?php if ($disableFields) echo "disabled"; ?>>
            </td>
            <td>
              <input type="datetime-local" name="approvedAt" value="<?php echo $value_approved; ?>" <?php if ($disableFields) echo "disabled"; ?>>
            </td>
            <td>
              <input type="text" name="office_<?php echo $safeStage; ?>" value="<?php echo $value_office; ?>" <?php if ($disableFields) echo "disabled"; ?>>
            </td>
            <td>
              <input type="text" name="remark" value="<?php echo $value_remark; ?>" <?php if ($disableFields) echo "disabled"; ?>>
            </td>
            <td>
              <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
              <?php 
                if ($allowSubmission) {
                    if ($currentSubmitted) {
                        // Stage is finished.
                        if ($_SESSION['admin'] == 1) {
                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Unsubmit</button>';
                        } else {
                            echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                        }
                    } else {
                        echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                    }
                }
              ?>
            </td>
         </tr>
         </form>
         <?php endforeach; ?>
       </tbody>
    </table>
  </div>
</body>
</html>
