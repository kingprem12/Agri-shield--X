<?php

	session_start();

	include('file_functions.php');
	
	if(isset($_SESSION['normal_user']))
	{
		header("location:app.php");
	}
	$error = "Welcome Back";
   
	if($_SERVER["REQUEST_METHOD"] == "POST")
	{
		// username and password sent from form 

		$username = $_POST['username']; 
		$password = $_POST['password'];
		
		if(!empty($_POST['username']) AND !empty($_POST['password']))
		{
			$length = get_ini_value_in("users.ini", "keys", "auto_increment_last_index");
			
			for($i = 1; $i <= $length; $i++)
			{
				if($username == get_ini_value_in("users.ini", "username", $i) and $password == get_ini_value_in("users.ini", "password", $i))
				{
					$_SESSION['normal_user'] = $username;
					$_SESSION['user_id'] = $i;
					header("location: app.php");
				}
			}
			
			$error = "Your Login Name or Password is invalid";
		}
		else
		{
			echo '<script language="javascript">';
			echo 'alert("Enter all details correctly")';
			echo '</script>';	
		}
		/* For Login with No Registration Page
		
		if($username === 'user' && $password === 'password')
		{
			$_SESSION['normal_user'] = $username;
			header("location: app.php");
		}
	   else 
		{
			$error = "Your Login Name or Password is invalid";
		}
		
		*/
	}
?>
<!DOCTYPE html>
<html >

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
	<title>Smart Irrigation &amp; Weather Prediction - Login</title>	
	
	<link rel="stylesheet" href="css/reset.min.css">
	<link rel="stylesheet" href="css/style.php?theme=purple">	
</head>

<body>
<div class="rain-layer" id="rainLayer" aria-hidden="true"></div>
<main class="login-page">
  <section class="login-intro">
    <div class="brand-mark">🌱</div>
    <span class="eyebrow">SMART FARM TECHNOLOGY</span>
    <h1><span class="title-main">Agri Shield</span><span class="title-sub">&amp; Weather Prediction</span></h1>
    <p>Monitor every field, understand sensor conditions and plan irrigation intelligently from one simple dashboard.</p>
    <div class="feature-list">
      <div class="feature"><span>💧</span><strong>Live Monitoring<small>Real-time sensor status</small></strong></div>
      <div class="feature"><span>📊</span><strong>Smart Prediction<small>Data-driven irrigation</small></strong></div>
      <div class="feature"><span>🌾</span><strong>4 Farm Areas<small>Independent area tracking</small></strong></div>
    </div>
  </section>
  <section class="login-panel">
    <div class="module form-module form-module-small">
      <div class="form"></div>
      <div class="form">
        <span class="login-icon">🔐</span>
        <h2>Welcome back</h2>
        <p class="login-copy">Sign in to open your smart farm dashboard.</p>
        <form method="post">
          <label class="login-label">Username<input type="text" name="username" placeholder="Enter your username" autocomplete="username" required></label>
          <label class="login-label">Password<input type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required></label>
          <button>Login to Dashboard <span>→</span></button>
        </form>
      </div>
      <div class="cta"><span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>
  </section>
</main>
<script>
(function () {
  var layer = document.getElementById('rainLayer');
  var dropCount = window.matchMedia('(max-width: 560px)').matches ? 100 : 500;
  for (var i = 0; i < dropCount; i++) {
    var drop = document.createElement('span');
    drop.className = 'rain-drop';
    drop.style.setProperty('--x', (Math.random() * 100).toFixed(2) + 'vw');
    drop.style.setProperty('--delay', (-Math.random() * 100).toFixed(2) + 's');
    drop.style.setProperty('--duration', (1.3 + Math.random() * 0.75).toFixed(2) + 's');
    drop.style.setProperty('--length', (10 + Math.random() * 38).toFixed(0) + 'px');
    drop.style.setProperty('--opacity', (0.6 + Math.random() * 0.5).toFixed(2));
    layer.appendChild(drop);
  }
})();
</script>

</body>
</html>
