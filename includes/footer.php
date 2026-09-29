<?php
/**
 * Closes the layout opened by header.php + sidebar.php. A page may set
 * $extraScripts (raw HTML string) before including this file to add
 * page-specific <script> tags right before </body>.
 */
?>
    </main>
  </div>
</div>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
