<?php if($_SERVER["REQUEST_METHOD"] == "POST"){ var_dump($_FILES); exit; } ?><form method="POST" enctype="multipart/form-data"><input type="file" name="f"><input type="submit"></form>
