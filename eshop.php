<?php
// eshop.php — statická stránka
?>
<!DOCTYPE html>
<html lang="sk">
<head>
  <meta charset="UTF-8">
  <title>E-shop pripravujeme</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: "Playfair Display", Georgia, serif;
      background: url('eshop-pozadi.png') no-repeat center center/cover;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      text-align: center;
      color: #2d2d2d;
    }
    .container {
      background: rgba(255,255,255,0.88);
      padding: 2rem 3rem;
      border-radius: 16px;
      box-shadow: 0 6px 24px rgba(0,0,0,0.15);
      max-width: 500px;
    }
    h1 {
      font-size: 2rem;
      margin-bottom: 1rem;
    }
    p {
      font-size: 1.1rem;
      margin-bottom: 2rem;
    }
    button {
      background: #6b4226; /* tmavší hnědá jako v logu */
      color: #fff;
      border: none;
      padding: 0.75rem 1.5rem;
      border-radius: 12px;
      font-size: 1rem;
      cursor: pointer;
      transition: background 0.25s;
    }
    button:hover {
      background: #8b5a3c;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>E-shop pre vás pripravujeme</h1>
    <p>Už čoskoro budete môcť nakupovať knihy priamo u nás.</p>
    <button onclick="history.back()">Späť</button>
  </div>
</body>
</html>