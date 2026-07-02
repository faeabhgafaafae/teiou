<header>
  <div class="header-left">
    <a class="back-btn" id="backBtn" href="index.php">&larr;</a>
    <div class="header-info">
      <h1 id="pageTitle"><?= htmlspecialchars($pageTitleDefault ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
      <div class="header-meta">
        <span class="date" id="pageDate"></span>
        <span class="grade-badge" id="pageBadge"></span>
      </div>
    </div>
  </div>
</header>
