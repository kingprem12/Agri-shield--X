<?php
	header("Content-type: text/css; charset: UTF-8");
	
	if(isset($_GET['theme']))
	{
		if($_GET['theme'] == 'blue')
		{
			$color = "#33b5e5";
		}
		else if($_GET['theme'] == 'red')
		{
			$color = "red";
		}
		else if($_GET['theme'] == 'green')
		{
			$color = "green";
		}
		else if($_GET['theme'] == 'yellow')
		{
			$color = "yellow";
		}
		else if($_GET['theme'] == 'purple')
		{
			$color = "purple";
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
  width: 100%;
  border-top: 5px solid <?php echo $color; ?>;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}
.form-module-medium {
  position: relative;
  background: #ffffff;
  max-width: 500px;
  width: 100%;
  border-top: 5px solid #92a8d1;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}

.form-module-extra-medium {
  position: relative;
  background: #ffffff;
  max-width: 650px;
  width: 100%;
  border-top: 5px solid <?php echo $color; ?>;
  box-shadow: 0 0 3px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
  padding: 10px;
}
.form-module-large {
  position: relative;
  background: #ffffff;
  max-width: 1000px;
  width: 100%;
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
.red {
	background: #ff2525 !important;
}
.green {
	background: #25ff25 !important;
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

ul.top-menu li a:hover:not(.active) {
    background-color: #111;
}

ul.top-menu .active {
    background-color: #4CAF50;
}