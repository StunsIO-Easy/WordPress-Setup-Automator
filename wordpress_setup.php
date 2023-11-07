<?php
$sucessInstall = false;
$htmlData = "";
$notify = [];
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $siteName = $_POST["siteName"];
    $dbAdminUser = $_POST["dbAdminUser"];
    $dbAdminPassword = $_POST["dbAdminPassword"];
    if (empty($siteName) || empty($dbAdminUser) || empty($dbAdminPassword)) {
        displayErrorToast("All fields are required.");
    } else {
        $error = false;
        $randomString = substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 5);
        $sanitizedSiteName = preg_replace("/[^a-zA-Z0-9]+/", "", $siteName);
        $sanitizedSiteName = substr($sanitizedSiteName, 0, 15);
        if (empty($sanitizedSiteName)) {
            $sanitizedSiteName = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 10);
        }
        $dbName = $randomString . "_" . $sanitizedSiteName;
        $dbUser = $dbName;
        $dbPassword = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+{}[]|:;<>,.?"), 0, 20);
        $mysqli = new mysqli("localhost", $dbAdminUser, $dbAdminPassword);
        if ($mysqli->connect_error) {
            die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
        }
        $dbUserS = $mysqli->real_escape_string($dbUser);
        $dbNameS = $mysqli->real_escape_string($dbName);
        $dbPasswordS = $mysqli->real_escape_string($dbPassword);
        $checkQuery = $mysqli->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $checkQuery->bind_param("s", $dbName);
        $checkQuery->execute();
        $checkQuery->store_result();
        if ($checkQuery->num_rows > 0) {
            displayErrorToast("Database already exists. Please try a different site name.");
            $mysqli->close();
            exit();
        }

        $checkUserQuery = $mysqli->prepare("SELECT User FROM mysql.user WHERE User = ?");
        $checkUserQuery->bind_param("s", $dbUser);
        $checkUserQuery->execute();
        $checkUserQuery->store_result();
        if ($checkUserQuery->num_rows > 0) {
            displayErrorToast("User already exists. Please try a different site name.");
            $mysqli->close();
            exit();
        }

        $checkUserQuery->close();
        $createDatabaseQuery = "CREATE DATABASE $dbNameS";
        if ($mysqli->query($createDatabaseQuery)) {
        } else {
            displayErrorToast("Error creating database: " . $mysqli->error);
            exit();
        }
        $createUserQuery = "CREATE USER '$dbUser'@'localhost' IDENTIFIED BY '$dbPasswordS'";
        if ($mysqli->query($createUserQuery)) {
        } else {
            displayErrorToast("Error creating user: " . $mysqli->error);
            exit();
        }
        $grantPrivilegesQuery = "GRANT ALL PRIVILEGES ON $dbNameS.* TO '$dbUserS'@'localhost'";
        if ($mysqli->query($grantPrivilegesQuery)) {
        } else {
            displayErrorToast("Error granting privileges: " . $mysqli->error);
            exit();
        }
        $flushPrivileges = $mysqli->prepare("FLUSH PRIVILEGES");
        if ($flushPrivileges->execute()) {
        } else {
            displayErrorToast("Error flushing privileges: " . $flushPrivileges->error);
            exit();
        }
        $flushPrivileges->close();
        $mysqli->close();
        $dbUserF = htmlspecialchars($dbUser, ENT_QUOTES, 'UTF-8');
        $dbPassF = htmlspecialchars($dbPassword, ENT_QUOTES, 'UTF-8');
        $dbNameF = htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8');
        $htmlData .= <<<EOF
    <div class="col-md-6">
    <div class="card mt-3">
        <div class="card-header bg-info text-white">
            Database Information
        </div>
        <div class="card-body">
            <ul class="list-unstyled">
            <li><strong>DB Name:</strong> $dbNameF</li>
            <li><strong>DB User:</strong> $dbUserF</li>
            <li><strong>DB Password:</strong> $dbPassF</li>
            </ul>
        </div>
    </div>
</div>
EOF;

        if ($error) {
            displayErrorToast("An error occurred. Please try again.");
        } else {
            $url = "https://wordpress.org/latest.zip";
            $zipFile = "latest.zip";
            file_put_contents($zipFile, file_get_contents($url));
            $zip = new ZipArchive;
            if ($zip->open($zipFile) === TRUE) {
                $zip->extractTo(getcwd());
                $zip->close();
                $wordpressDir = getcwd() . "/wordpress";
                function recursiveMove($src, $dest)
                {
                    if (is_dir($src)) {
                        @mkdir($dest);
                        $files = glob("$src/{*,.[!.]*}", GLOB_BRACE | GLOB_NOSORT);
                        foreach ($files as $file) {
                            $fileDest = str_replace($src, $dest, $file);
                            recursiveMove($file, $fileDest);
                        }
                        rmdir($src);
                    } else {
                        rename($src, $dest);
                    }
                }
                recursiveMove($wordpressDir, getcwd());
                if (file_exists($zipFile)) {
                    unlink($zipFile);
                }
                if (file_exists("./wp-config.php")) {
                    unlink("./wp-config.php");
                }
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $baseDirectory = dirname($_SERVER['REQUEST_URI']);
                $currentUrl = "$protocol://$_SERVER[HTTP_HOST]$baseDirectory";
                $userPassword = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+{}[]|:;<>,.?"), 0, 20);
                $userName = "Admin";
                $userEmail = "admin@localhost.local";
                $url = "$currentUrl/wp-admin/setup-config.php?step=2";
                $data = http_build_query(array(
                    'dbname' => $dbName,
                    'uname' => $dbUser,
                    'pwd' => $dbPassword,
                    'dbhost' => 'localhost',
                    'prefix' => 'wp_',
                    'language' => '',
                    'submit' => 'Submit'
                ));
                $options = array(
                    'http' => array(
                        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method' => 'POST',
                        'content' => $data
                    )
                );
                try {
                    $context = stream_context_create($options);
                    $response = file_get_contents($url, false, $context);
                } catch (Exception $e) {
                    $httpCode = http_response_code();
                    if ($httpCode !== 200) {
                        if (strpos($response, "The file wp-config.php already exists") !== false) {
                            displayErrorToast('Error: The file wp-config.php already exists.');
                            exit;
                        } else {
                            displayErrorToast('Error: Unexpected response code - ' . $httpCode);
                            exit;
                        }
                    }
                }
                $installUrl = "$currentUrl/wp-admin/install.php?step=2";
                $installData = http_build_query(array(
                    'weblog_title' => $siteName,
                    'user_name' => $userName,
                    'admin_password' => $userPassword,
                    'admin_password2' => $userPassword,
                    'pw_weak' => 'on',
                    'admin_email' => $userEmail,
                    'Submit' => 'Install WordPress',
                    'language' => ''
                ));
                $installOptions = array(
                    'http' => array(
                        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method' => 'POST',
                        'content' => $installData
                    )
                );
                $installContext = stream_context_create($installOptions);
                $installResponse = file_get_contents($installUrl, false, $installContext);
                if ($installResponse === false) {
                    displayErrorToast('Error: Unable to make the request.');
                    exit;
                }
                $adminURL = "$currentUrl/wp-admin/";
                $adminUserF = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
                $adminPassF = htmlspecialchars($userPassword, ENT_QUOTES, 'UTF-8');
                $htmlData .= <<< EOF
            <div class="col-md-6">
<div class="card mt-3">
<div class="card-header bg-info text-white">WP-Admin Information</div>
<div class="card-body">
  <ul class="list-unstyled">
    <li>
      <strong>WP-Admin URL:</strong> <a href="$adminURL">Access Admin Panel</a></li>
    <li>
      <strong>WP-Admin User:</strong> $adminUserF</li>
    <li>
      <strong>WP-Admin Password:</strong> $adminPassF</li>
  </ul>
</div>
</div>
</div>
EOF;
                $sucessInstall = true;
            } else {
                displayErrorToast("Error extracting file. Please try again.");
            }
        }
    }
}
function displayErrorToast($message)
{
    global $notify;
    $notify[] = '<div class="position-fixed top-0 end-0 p-3" style="z-index: 11">
            <div class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header bg-danger text-white">
                    <strong class="me-auto">Error</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ' . $message . '
                </div>
            </div>
        </div>
        <script>
            var toast = new bootstrap.Toast(document.querySelector(".toast"));
            toast.show();
        </script>';
}
function displaySuccessToast($message)
{
    global $notify;
    $notify[] =  '<div class="position-fixed top-0 end-0 p-3" style="z-index: 11">
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-success text-white">
                <strong class="me-auto">Success</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                ' . $message . '
            </div>
        </div>
    </div>
    <script>
        var toast = new bootstrap.Toast(document.querySelector(".toast"));
        toast.show();
    </script>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>WordPress Installation Form</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <style>
        body {
            color: white;
            background-size: cover;
            background-color: #0073AA;
        }

        .toast-body {
            background-color: #0073AA;
        }

        .overlay {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            background: rgb(24 24 24 / 80%);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 999;
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .overlay.active {
            visibility: visible;
            opacity: 1;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        .bg {
            max-width: 800px;
            animation: fadeInUp 1s;
            backdrop-filter: blur(100px);
            background: #5d00005e;
            padding-top: 40px;
        }

        .card-body {
            background-color: #0073AA;
        }

        .card-header {
            color: #3f3f3f !important;
            font-weight: bold;
        }

        a {
            color: #191E23;
            text-decoration: none;
            transition: color 0.3s;
        }

        button {
            background-color: #0073AA;
        }

        a:hover,
        a:focus {
            color: #32373C;
        }

        b,
        strong {
            color: #ced5db;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translate3d(0, 50%, 0);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        ::-webkit-scrollbar {
            width: 12px;
        }

        ::-webkit-scrollbar-track {
            background-color: #005893;
        }

        ::-webkit-scrollbar-thumb {
            background-color: #0093bc;
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background-color: #003f6d;
        }
    </style>
</head>

<body>
    <div class="overlay" id="overlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    <div class="container mt-5 pb-5 bg mb-5">
        <div class="row justify-content-center">
            <?php if ((!isset($_POST)  || ($sucessInstall === false))) { ?>
                <div class="col-9 col-xl-10 col-md-8">
                    <h2 class="text-center mb-4">WordPress Setup Automator</h2>
                    <p class="lead text-center w-75 mx-auto mb-4">Please provide the following information to create your WordPress site.</p>
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="mb-3">
                            <label for="siteName" class="form-label">Site Name</label>
                            <input type="text" class="form-control" id="siteName" name="siteName">
                        </div>
                        <div class="mb-3">
                            <label for="dbAdminUser" class="form-label">MySQL Admin User</label>
                            <input type="text" class="form-control" id="dbAdminUser" name="dbAdminUser">
                        </div>
                        <div class="mb-3">
                            <label for="dbAdminPassword" class="form-label">MySQL Admin Password</label>
                            <input type="password" class="form-control" id="dbAdminPassword" name="dbAdminPassword">
                        </div>
                        <button type="submit" id="submitButton" class="btn btn-primary">Submit</button>
                    </form>
                    <div class="mt-4">
                        <h5>Notes:</h5>
                        <ol>
                            <li>MySQL admin credentials are required to create users and a database.</li>
                            <li>No information provided in this form is being saved or stored.</li>
                            <li>This will overwrite the existing wp-config.php file and create a new database and user.</li>
                        </ol>
                    </div>
                </div>
            <?php } else {
            ?> <h2 class="text-center mb-4">Database and WP-Admin Information</h2>
                <p class="text-center mb-4">Please find below the details for your WordPress installation.</p>
                <p class="ps-4 pe-4"><strong>Security Notice:</strong> Please remember to delete this script for security purposes after copying the information.</p>
            <?php
                echo '<div class="row">';
                echo $htmlData;
                echo '</div>';
            } ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js" integrity="sha384-IQsoLXl5PILFhosVNubq5LC7Qb9DXgDA9i+tQ8Zj3iwWAwPtgFTxbJ8NT4GN1R8p" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js" integrity="sha384-cVKIPhGWiC2Al4u+LWgxfKTRIcfu0JTxR+EQDz/bgldoEyl4H0zUF0QKbrJ0EcQF" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (document.getElementById('submitButton')) {
                document.getElementById('submitButton').addEventListener('click', function() {
                    document.getElementById('overlay').classList.add('active');
                });
            }
        });
    </script>
    <?php
    if (isset($notify)) {
        foreach ($notify as $notification) {
            echo $notification;
        }
    } ?>
</body>

</html>
