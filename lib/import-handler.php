<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $filename      = basename($_FILES['csv_file']['name']);
    $file          = $_FILES['csv_file']['tmp_name'];
    $expense_count = 0;
    $income_count  = 0;
    $success_count = 0;
    $error_count   = 0;

    if (($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file)) {
        $error_message = t('Error opening the CSV file.');
        return;
    }
    if (($handle = fopen($file, 'r')) !== false) {
        // Skip first line
        fgetcsv($handle, 1000, ',', '"', '');

        while (($data = fgetcsv($handle, 1000, ',', '"', '')) !== false) {

            $date_input  = $data[0] ?? '';  // A
            $date_object = DateTime::createFromFormat('d/m/Y', $date_input);
            $date        = $date_object ? $date_object->format('Y-m-d') : date('Y-m-d');

            $category      = $data[1] ?? ""; // B
            $macrocategory = $data[1] ?? ""; // B
            $description   = $data[2] ?? ""; // C
            $expense       = floatvalue($data[3] ?? "") ?? ""; // D
            $income        = floatvalue($data[4] ?? "") ?? ""; // E
            $note          = $data[5] ?? ""; // F
            $source        = $data[6] ?? ""; // G

            if (!empty($expense)) {
                $type = "Expense";
                $amount = $expense;
            } elseif (!empty($income)) {
                $type = "Income";
                $amount = $income;
            }

            if (empty($expense) && empty($income)) continue;

            // Force to positive number
            $amount = $amount<0 ? $amount*-1 : $amount;


            try {
                if (!empty($expense) || !empty($income)) {
                    $query = "INSERT INTO cf_entries (user_id, type, description, date, amount, category, macrocategory, source, note, currency, exchange_rate, rate_currency) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param('isssdssssss', $_SESSION['user_id'], $type, $description, $date, $amount, $category, $macrocategory, $source, $note, $_SESSION['currency'], $_SESSION['currency']);
                    $stmt->execute();
                    if ($type == 'Income') {
                        $income_count++;
                    } else {
                        $expense_count++;
                    }
                }
                $success_count++;
            } catch (Exception $e) {
                error_log((string)$e);
                $error_count++;
            }
        }
        fclose($handle);
    } else {
        $error_message = t('Error opening the CSV file.');
    }

    if ($error_count === 0) {
        $success_message = t("Successfully imported $success_count rows from $filename. [ Incomes: $income_count / Expenses: $expense_count ]");
    } else {
        $error_message = t("Imported $success_count rows with $error_count errors.");
    }
}
