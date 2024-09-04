<?php

class ArrayToHTMLTable {

    // Method to convert a multidimensional array to an HTML table
    public function convert(array $array, $tableClass = '', $includeHeaders = true) {
        // Start the table with inline styles for Outlook
        $html = "<table class=\"$tableClass\" style=\"border-collapse: collapse; width: 100%;\">";

        // Check if the array is empty
        if (empty($array)) {
            $html .= "<tr><td colspan=\"100%\" style=\"border: 1px solid #dddddd; padding: 8px;\">No data available</td></tr>";
            $html .= "</table>";
            return $html;
        }

        // If headers should be included
        if ($includeHeaders) {
            $html .= $this->generateHeaders($array);
        }

        // Generate table rows
        $html .= $this->generateRows($array);

        // End the table
        $html .= "</table>";

        return $html;
    }

    // Method to generate the headers from the keys of the first element in the array
    private function generateHeaders($array) {
        $html = "<thead><tr>";

        // Get the headers from the first row of the array
        $headers = array_keys(reset($array));

        foreach ($headers as $header) {
            $html .= "<th style=\"border: 1px solid #dddddd; padding: 8px; background-color: #f2f2f2; text-align: left;\">" . htmlspecialchars($header) . "</th>";
        }

        $html .= "</tr></thead>";

        return $html;
    }

    // Method to generate rows of the table
    private function generateRows($array) {
        $html = "<tbody>";

        foreach ($array as $row) {
            $html .= "<tr>";
            foreach ($row as $cell) {
                if (is_array($cell)) {
                    // Handle nested arrays
                    $html .= "<td style=\"border: 1px solid #dddddd; padding: 8px;\">" . $this->convert($cell) . "</td>";
                } else {
                    $html .= "<td style=\"border: 1px solid #dddddd; padding: 8px;\">" . htmlspecialchars($cell) . "</td>";
                }
            }
            $html .= "</tr>";
        }

        $html .= "</tbody>";

        return $html;
    }
}


?>
