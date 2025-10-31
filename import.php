<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'conf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $filename      = $_FILES['csv_file']['name'];
    $file          = $_FILES['csv_file']['tmp_name'];
    $expense_count = 0;
    $income_count  = 0;
    $success_count = 0;
    $error_count   = 0;

    if (($handle = fopen($file, 'r')) !== false) {
        // Skip first line
        fgetcsv($handle, 1000, ",");

        while (($data = fgetcsv($handle, 1000, ",")) !== false) {

            $date_input  = $data[0]; // A
            $date_object = DateTime::createFromFormat('d/m/Y', $date_input);
            $date        = $date_object ? $date_object->format('Y-m-d') : date('Y-m-d');

            $category      = $data[1] ?? ""; // B
            $macrocategory = $data[1] ?? ""; // B
            $description   = $data[2] ?? ""; // C
            $expense       = floatvalue($data[3]) ?? ""; // D
            $income        = floatvalue($data[4]) ?? ""; // E
            $note          = $data[5] ?? ""; // F
            $source        = $data[6] ?? ""; // G

            if (!empty($expense)) {
                $type = "Expense";
                $amount = $expense;
            } elseif (!empty($income)) {
                $type = "Income";
                $amount = $income;
            }

            // Force to positive number
            $amount = $amount<0 ? $amount*-1 : $amount;


            try {
                if (!empty($expense) || !empty($income)) {
                    $query = "INSERT INTO Entries (user_id, type, description, date, amount, category, macrocategory, source, note) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('isssdssss', $_SESSION['user_id'], $type, $description, $date, $amount, $category, $macrocategory, $source, $note);
                    $stmt->execute();
                    if ($type == 'Income') {
                        $income_count++;
                    } else {
                        $expense_count++;
                    }
                }
                $success_count++;
            } catch (Exception $e) {
                var_dump($e);
                $error_count++;
            }
        }
        fclose($handle);
    } else {
        $error_message = t('Error opening the CSV file.');
    }

    if ($error_count === 0) {
        $success_message = t("Successfully imported $success_count rows from $filename. <br>[ Incomes: $income_count / Expenses: $expense_count ]");
    } else {
        $error_message = t("Imported $success_count rows with $error_count errors.");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="res/favicon.svg" sizes="any" type="image/svg+xml">
    <link rel="stylesheet" type="text/css" href="res/style.css">
    <title><?php echo t('Import CSV'); ?></title>
</head>
<body>
    <?php printMenu(); ?>
    <h1><?php echo t('Import CSV'); ?></h1>
    <?php if (isset($success_message)): ?>
        <div class="success"><?php echo $success_message; ?></div>
    <?php elseif (isset($error_message)): ?>
        <div class="error"><?php echo $error_message; ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" class="small-form">
        <div style="font-family: monospace; font-size: 9pt; border: 1px solid #ddd; padding: 5px; margin: 10px;">
            <b><?= t("Excel → Save in .csv"); ?></b>
            <br>
            <br>A = <?= t("Date"); ?> (dd/mm/yyyy)
            <br>B = <?= t("Category"); ?>
            <br>C = <?= t("Description"); ?>
            <br>D = <?= t("Expense"); ?>
            <br>E = <?= t("Income"); ?>
            <br>F = <?= t("Note"); ?>
        </div>
        <label for="csv_file"><?php echo t('Choose a CSV file to upload:'); ?></label>
        <input type="file" name="csv_file" accept=".csv" required>
        <button class="w100" type="submit"><?php echo t('Import'); ?></button>
    </form>
</body>
</html>
