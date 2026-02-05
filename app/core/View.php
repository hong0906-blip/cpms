<?php
namespace App\Core;

class View
{
    public static function render($viewPath, $data = array())
    {
        $viewFile = __DIR__ . '/../views/' . $viewPath . '.php';
        if (!file_exists($viewFile)) {
            http_response_code(500);
            echo "View not found: " . htmlspecialchars($viewPath, ENT_QUOTES, 'UTF-8');
            exit;
        }

        $title = isset($data['title']) ? $data['title'] : 'CPMS';
        $hideLayout = !empty($data['hideLayout']);
        $selectedMenu = isset($data['selectedMenu']) ? $data['selectedMenu'] : '대시보드';

        $flash = isset($data['flash']) ? $data['flash'] : null;
        if ($flash === null) {
            $flash = \flash_get();
        }

        extract($data);

        if ($hideLayout) {
            require $viewFile;
            return;
        }

        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/layout/sidebar.php';
        require $viewFile;
        require __DIR__ . '/../views/layout/footer.php';
    }
}