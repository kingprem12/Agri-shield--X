<?php
	header("Content-type: text/css; charset: UTF-8");
	
	if(isset($_GET['theme']))
	{
		if($_GET['theme'] == 'blue')
		{
			$color = "#33b5e5";
		}
		else if($_GET['theme'] == 'orange')
		{
			$color = "#d16500";
		}
		else if($_GET['theme'] == 'green')
		{
			$color = "green";
		}
		else if($_GET['theme'] == 'yellow')
		{
			$color = "#d6ae00";
		}
		else if($_GET['theme'] == 'purple')
		{
			$color = "#c400c4";
		}
		else if($_GET['theme'] == 'gray')
		{
			$color = "#aaa";
		}
	}
	else
	{
		$color = "#33b5e5";
	}
?>

body {
  background: #e9e9e9;
  color: #666666;
  font-family: 'RobotoDraft', 'Roboto', sans-serif;
  font-size: 14px;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

/* Pen Title */
.pen-title {
  padding: 50px 0;
  text-align: center;
  letter-spacing: 2px;
}
.pen-title h1 {
  margin: 0 0 20px;
  font-size: 48px;
  font-weight: 300;
}
.pen-title span {
  font-size: 12px;
}
.pen-title span .fa {
  color: <?php echo $color; ?>;
}
.pen-title span a {
  color: <?php echo $color; ?>;
  font-weight: 600;
  text-decoration: none;
}

/* Form Module */
.form-module-small {
  position: relative;
  background: #ffffff;
  max-width: 320px;
  width: 90%;
  border-top: 5px solid <?php echo $color; ?>;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}
.form-module-medium {
  position: relative;
  background: #ffffff;
  max-width: 500px;
  width: 90%;
  border-top: 5px solid <?php echo $color; ?>;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}
.form-module-extra-medium {
  position: relative;
  background: #ffffff;
  max-width: 650px;
  width: 90%;
  border-top: 5px solid <?php echo $color; ?>;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}
.form-module-large {
  position: relative;
  background: #ffffff;
  max-width: 1000px;
  width: 90%;
  border-top: 5px solid <?php echo $color; ?>;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}
.form-module .form {
  display: none;
  padding: 40px;
}
.form-module .form:nth-child(2) {
  display: block;
}
.form-module h2 {
  margin: 0 0 20px;
  color: <?php echo $color; ?>;
  font-size: 18px;
  font-weight: 400;
  line-height: 1;
}
.form-module input {
  outline: none;
  display: block;
  width: 100%;
  border: 1px solid #d9d9d9;
  margin: 0 0 20px;
  padding: 10px 15px;
  box-sizing: border-box;
  font-wieght: 400;
  -webkit-transition: 0.3s ease;
  transition: 0.3s ease;
}
.form-module .medium-width {
  outline: none;
  display: block;
  width: 500px;
  border: 1px solid #d9d9d9;
  margin: 0 0 20px;
  padding: 10px 15px;
  box-sizing: border-box;
  font-wieght: 400;
  -webkit-transition: 0.3s ease;
  transition: 0.3s ease;
}
.form-module .small-width {
  outline: none;
  display: block;
  width: 300px;
  border: 1px solid #d9d9d9;
  margin: 0 0 20px;
  padding: 10px 15px;
  box-sizing: border-box;
  font-wieght: 400;
  -webkit-transition: 0.3s ease;
  transition: 0.3s ease;
}
.form-module select {
  outline: none;
  display: block;
  width: 100%;
  line-height: 35px;
  border: 1px solid #d9d9d9;
  margin: 0 0 20px;
  padding: 10px 15px;
  box-sizing: border-box;
  font-wieght: 400;
  -webkit-transition: 0.3s ease;
  transition: 0.3s ease;
}
.form-module select option {
  height: 35px;
  line-height: 35px;
}
.form-module input:focus {
  border: 1px solid <?php echo $color; ?>;
  color: #333333;
}
.form-module button {
  cursor: pointer;
  background: <?php echo $color; ?>;
  width: 100%;
  border: 0;
  padding: 10px 15px;
  color: #ffffff;
  -webkit-transition: 0.3s ease;
  transition: 0.3s ease;
}
.button-small {
  cursor: pointer;
  background: <?php echo $color; ?>;
  width: 100%;
  border: 0;
  padding: 10px 15px;
  color: #ffffff;
  -webkit-transition: 0.3s ease;
  text-decoration: none;
  transition: 0.3s ease;
}
.button-medium {
  cursor: pointer;
  background: <?php echo $color; ?>;
  width: 100%;
  border: 0;
  padding: 20px 35px;
  color: #ffffff;
  -webkit-transition: 0.3s ease;
  text-decoration: none;
  transition: 0.3s ease;
}
.button-large {
  cursor: pointer;
  background: <?php echo $color; ?>;
  width: 100%;
  border: 0;
  padding: 25px 55px;
  color: #ffffff;
  -webkit-transition: 0.3s ease;
  text-decoration: none;
  transition: 0.3s ease;
}

.button-large img {
  height: 48px;
  width: 48px;
  margin: -16px 16px -20px -46px;
}
.red {
	background: #ff2525 !important;
}
.green {
	background: #94bd17 !important;
}
.purple {
	background: purple !important;
}
.yellow {
	background: yellow !important;
}
.gray {
	background: #aaa !important;
}
.form-module button:hover {
  background: #178ab4;
}
.form-module .cta {
  background: #f2f2f2;
  width: 100%;
  padding: 15px 40px;
  box-sizing: border-box;
  color: #666666;
  font-size: 12px;
  text-align: center;
}
.form-module .cta a {
  color: #333333;
  text-decoration: none;
}
h2.headings {
	font-size: 2em;
	margin: 10px;
	padding: 10px;
	padding-bottom: 35px;
}
.content {
	clear: both;
	padding: 10px;
}
.disabled {
	opacity: 0.5;
	pointer-events: none;
	cursor: default;
}
table {
	border-collapse: collapse;
	width: 100%;
}

th, td {
	text-align: left;
	padding: 8px;
}

tr:nth-child(even){
	background-color: #f2f2f2
}

th {
	background-color: #4CAF50;
	color: white;
}

ul.top-menu {
    list-style-type: none;
    margin: -10px;
    padding: 0;
    overflow: hidden;
    background-color: #333;
	border-bottom: 5px solid <?php echo $color; ?>;
	box-shadow: 0 3px 4px;
}

ul.top-menu li {
    float: left;
}

ul.top-menu li a {
    display: block;
    color: white;
    text-align: center;
    padding: 14px 16px;
    text-decoration: none;
}

ul.top-menu li a.side-menu {
	display: inline-block; 
	height: 30px; 
	text-align: center; 
	vertical-align: middle; 
	background: #911; 
	color: #fff; 
	text-decoration: none; 
	padding: 6px;
}
ul.top-menu li a.side-menu.active {
	background: #4CAF50;
}

ul.top-menu li a.side-menu img {
	vertical-align: middle; 
	padding-right: 5px;
	width: 28px;
}

ul.top-menu li a:hover:not(.active) {
    background-color: #111;
}

ul.top-menu .active {
    background-color: #4CAF50;
}
.note { 
	position: relative; 
	background: #f4fd4d; 
	padding: 20px;
	font-size: 1.2em; 
	border-radius: 1em; 
} 
.note:after { 
	content: ''; 
	position: absolute; 
	top: 0; 
	left: 50%; 
	width: 0; 
	height: 0; 
	border: 12px solid transparent; 
	border-bottom-color: #f4fd4d; 
	border-top: 0; 
	margin-left: -12px; 
	margin-top: -12px; 
}

/* Colorful login theme */
body{min-height:100vh;background-image:linear-gradient(120deg,rgba(25,20,75,.62),rgba(0,105,116,.38)),url('../images/home.jpg');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed;color:#fff;overflow-x:hidden}
body:before,body:after{content:"";position:fixed;border-radius:50%;background:#fff;opacity:.1;animation:loginFloat 7s ease-in-out infinite}body:before{width:320px;height:320px;left:-100px;top:-90px}body:after{width:420px;height:420px;right:-130px;bottom:-170px;animation-delay:-3s}
.pen-title{position:relative;z-index:1}.pen-title h1{font-weight:700;text-shadow:0 8px 25px rgba(20,12,70,.35);animation:loginDrop .7s ease-out}
.form-module-small{position:relative;z-index:1;border:1px solid rgba(255,255,255,.7);border-top:6px solid #ff4d9d;border-radius:20px;background:rgba(255,255,255,.94);box-shadow:0 22px 60px rgba(20,12,70,.35);overflow:hidden;animation:loginDrop .7s .12s ease-out both}
.form-module h2{color:#6135c5;font-weight:700}.form-module input{border:2px solid #e0daf6;border-radius:9px}.form-module input:focus{border-color:#6c3ce9;box-shadow:0 0 0 4px rgba(108,60,233,.12)}.form-module button{border-radius:9px;background:linear-gradient(135deg,#6c3ce9,#ef3f91);font-weight:bold;box-shadow:0 7px 18px rgba(132,57,189,.3)}.form-module button:hover{background:linear-gradient(135deg,#5930cc,#dc2d82);transform:translateY(-2px)}.form-module .cta{background:#f5f1ff}.form-module .cta a{color:#6042bd}
@keyframes loginGradient{50%{background-position:100% 100%}}@keyframes loginFloat{50%{transform:translate(35px,-25px) scale(1.08)}}@keyframes loginDrop{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}

/* Responsive smart-farm login */
*{box-sizing:border-box}.login-page{width:min(1180px,calc(100% - 48px));min-height:100vh;margin:auto;display:grid;grid-template-columns:minmax(0,1.25fr) minmax(340px,.75fr);gap:70px;align-items:center;padding:54px 0;position:relative;z-index:2}.login-intro{max-width:680px;text-shadow:0 3px 18px rgba(10,25,50,.28)}.brand-mark{width:64px;height:64px;display:grid;place-items:center;border-radius:20px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);backdrop-filter:blur(12px);font-size:34px;box-shadow:0 12px 35px rgba(10,25,50,.2);margin-bottom:24px}.eyebrow{display:inline-block;font-size:13px;font-weight:800;letter-spacing:2.2px;color:#d9ffbd;margin-bottom:15px}.login-intro h1{font-size:clamp(44px,5vw,72px);line-height:1.04;margin:0;color:#fff;font-weight:800;letter-spacing:-2px}.login-intro h1 span{color:#c9ffb1}.login-intro>p{max-width:620px;font-size:18px;line-height:1.7;color:rgba(255,255,255,.9);margin:24px 0 30px}.feature-list{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.feature{display:flex;align-items:center;gap:10px;padding:14px;border:1px solid rgba(255,255,255,.25);background:rgba(14,43,66,.28);backdrop-filter:blur(10px);border-radius:14px;transition:.25s}.feature:hover{transform:translateY(-5px);background:rgba(255,255,255,.18)}.feature>span{font-size:25px}.feature strong{font-size:13px;color:#fff}.feature small{display:block;color:rgba(255,255,255,.7);font-size:10px;margin-top:4px;font-weight:400}.login-panel{display:flex;justify-content:flex-end}.form-module-small{width:100%;max-width:420px;margin:0;border-top:0;border-radius:26px;background:rgba(255,255,255,.93);backdrop-filter:blur(20px);box-shadow:0 28px 80px rgba(5,22,55,.38);overflow:hidden}.form-module-small:before{content:"";display:block;height:7px;background:linear-gradient(90deg,#35c76f,#26a9df,#8a3de6,#f13d91)}.form-module .form:nth-child(2){padding:42px}.login-icon{display:grid;place-items:center;width:52px;height:52px;border-radius:16px;background:linear-gradient(135deg,#e9fff0,#e9e7ff);font-size:25px;margin-bottom:22px}.form-module h2{font-size:29px;margin:0 0 8px;color:#302268;font-weight:800}.login-copy{color:#74718a;line-height:1.5;margin:0 0 28px}.login-label{display:block;color:#46415c;font-size:13px;font-weight:700;margin-bottom:18px}.form-module .login-label input{margin:8px 0 0;width:100%;height:48px;padding:0 15px;border-radius:11px;background:#fff}.form-module button{height:50px;border-radius:12px;font-size:14px;display:flex;align-items:center;justify-content:center;gap:10px}.form-module button span{font-size:21px;transition:.2s}.form-module button:hover span{transform:translateX(5px)}.form-module .cta{padding:17px;background:rgba(243,240,255,.9);color:#6446bb;text-align:center}.form-module .cta span{font-weight:600}
@media(max-width:900px){body{background-position:60% center}.login-page{grid-template-columns:1fr;gap:34px;width:min(680px,calc(100% - 36px));padding:38px 0}.login-intro{text-align:center;margin:auto}.brand-mark{margin:0 auto 18px}.login-intro h1{font-size:clamp(38px,8vw,58px)}.login-intro>p{font-size:16px;margin:18px auto 23px}.feature-list{max-width:620px;margin:auto}.login-panel{justify-content:center}.form-module-small{max-width:460px}}
@media(max-width:560px){body{background-position:64% center;background-attachment:scroll}.login-page{width:calc(100% - 24px);padding:24px 0 34px;gap:25px}.login-intro h1{font-size:34px;letter-spacing:-1px}.login-intro>p{font-size:14px;line-height:1.55}.eyebrow{font-size:10px;letter-spacing:1.6px}.brand-mark{width:50px;height:50px;font-size:27px;border-radius:15px}.feature-list{grid-template-columns:1fr;gap:8px}.feature{padding:10px 13px;text-align:left}.feature small{font-size:11px}.form-module-small{border-radius:20px}.form-module .form:nth-child(2){padding:28px 22px}.form-module h2{font-size:25px}.login-copy{font-size:13px;margin-bottom:22px}.form-module .login-label input{height:46px}.form-module button{height:48px}.form-module .cta{padding:14px}}
@media(max-height:720px) and (min-width:901px){.login-page{padding:24px 0}.login-intro h1{font-size:52px}.brand-mark{margin-bottom:14px}.login-intro>p{margin:15px 0 20px}.form-module .form:nth-child(2){padding:30px 36px}.login-icon{margin-bottom:12px}.login-copy{margin-bottom:17px}.login-label{margin-bottom:12px}}
.login-intro h1 .title-main,.login-intro h1 .title-sub{display:block}.login-intro h1 .title-main{color:#fff;white-space:nowrap}.login-intro h1 .title-sub{color:#c9ffb1;font-size:.76em;line-height:1.16;white-space:nowrap;margin-top:5px}
@media(max-width:560px){.login-intro h1 .title-main,.login-intro h1 .title-sub{white-space:normal}.login-intro h1 .title-sub{font-size:.8em;margin-top:7px}}

/* Rain animation for the login background */
.rain-layer{position:fixed;inset:0;overflow:hidden;pointer-events:none;z-index:1}.rain-layer:after{content:"";position:absolute;left:0;right:0;bottom:0;height:24%;background:linear-gradient(to top,rgba(172,224,255,.1),transparent);animation:rainMist 3.5s ease-in-out infinite}.rain-drop{position:absolute;top:-90px;left:var(--x);width:2px;height:var(--length);border-radius:999px;background:linear-gradient(to bottom,transparent,rgba(220,244,255,.95));opacity:var(--opacity);filter:drop-shadow(0 0 2px rgba(190,230,255,.7));transform:rotate(10deg);animation:rainFall var(--duration) linear var(--delay) infinite}.rain-drop:nth-child(3n){width:1px}.rain-drop:nth-child(4n){filter:blur(.4px)}
@keyframes rainFall{0%{transform:translate3d(0,-100px,0) rotate(10deg)}100%{transform:translate3d(-90px,calc(100vh + 150px),0) rotate(10deg)}}@keyframes rainMist{50%{opacity:.55}}
@media(max-width:560px){.rain-drop{width:1px}.rain-layer:after{height:16%}}
@media(prefers-reduced-motion:reduce){.rain-layer{display:none}}
