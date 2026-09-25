<?php
include('../file_functions.php');
$chart_data = "";
?>
window.onload = function () {
	var chart = new CanvasJS.Chart("dailychartContainer", {
		title: {
			text: "Daily",
			fontSize: 30
		},
		animationEnabled: true,
		axisX: {
			gridColor: "Silver",
			tickColor: "silver"
		},
		toolTip: {
			shared: true
		},
		theme: "theme2",
		axisY: {
			gridColor: "Silver",
			tickColor: "silver"
		},
		legend: {
			verticalAlign: "center",
			horizontalAlign: "right"
		},
		data: [
		{
			type: "line",
			showInLegend: true,
			lineThickness: 2,
			name: <?php echo "\"$_GET[hour_1]\""; ?>,
			markerType: "square",
			color: "#F08080",
			dataPoints: [
			<?php			
				$config_data = parse_ini_file("../flat_file.ini", true);

				foreach( $config_data as $section => $data )
				{
					if($section == "$_GET[hour_1]")
					{
						foreach( $data as $hour => $value )
						{
							if(!empty($value))
							{
								$chart_data .= "{ x:$hour , y: $value },";
							}
						}
					}
				}
				echo substr($chart_data, 0, -1);
				$chart_data = "";
			?>
			]
		},
		{
			type: "line",
			showInLegend: true,
			name: <?php echo "\"$_GET[hour_2]\""; ?>,
			color: "#20B2AA",
			lineThickness: 2,

			dataPoints: [
			<?php			
				$config_data = parse_ini_file("../flat_file.ini", true);

				foreach( $config_data as $section => $data )
				{
					if($section == "$_GET[hour_2]")
					{
						foreach( $data as $hour => $value )
						{
							if(!empty($value))
							{
								$chart_data .= "{ x:$hour , y: $value },";
							}
						}
					}
				}
				echo substr($chart_data, 0, -1);
				$chart_data = "";
			?>
			]
		}
		],
		legend: {
			cursor: "pointer",
			itemclick: function (e) {
				if (typeof (e.dataSeries.visible) === "undefined" || e.dataSeries.visible) {
					e.dataSeries.visible = false;
				}
				else {
					e.dataSeries.visible = true;
				}
				chart.render();
			}
		}
	});

	chart.render();
	
	var chart = new CanvasJS.Chart("monthlychartContainer", {
		title: {
			text: "Monthly",
			fontSize: 30
		},
		animationEnabled: true,
		axisX: {
			gridColor: "Silver",
			tickColor: "silver"
		},
		toolTip: {
			shared: true
		},
		theme: "theme2",
		axisY: {
			gridColor: "Silver",
			tickColor: "silver"
		},
		legend: {
			verticalAlign: "center",
			horizontalAlign: "right"
		},
		data: [
		{
			type: "line",
			showInLegend: true,
			lineThickness: 2,
			name: <?php echo "\"$_GET[day_1]\""; ?>,
			markerType: "square",
			color: "#F08080",
			dataPoints: [
			<?php			
				$config_data = parse_ini_file("../flat_file.ini", true);

				foreach( $config_data as $section => $data )
				{
					if($section == "$_GET[day_1]")
					{
						foreach( $data as $day => $value )
						{
							if(!empty($value))
							{
								$chart_data .= "{ x:$day , y: $value },";
							}
						}
					}
				}
				echo substr($chart_data, 0, -1);
				$chart_data = "";
			?>
			]
		},
		{
			type: "line",
			showInLegend: true,
			name: <?php echo "\"$_GET[day_2]\""; ?>,
			color: "#20B2AA",
			lineThickness: 2,

			dataPoints: [
			<?php			
				$config_data = parse_ini_file("../flat_file.ini", true);

				foreach( $config_data as $section => $data )
				{
					if($section == "$_GET[day_2]")
					{
						foreach( $data as $day => $value )
						{
							if(!empty($value))
							{
								$chart_data .= "{ x:$day , y: $value },";
							}
						}
					}
				}
				echo substr($chart_data, 0, -1);
				$chart_data = "";
			?>
			]
		}
		],
		legend: {
			cursor: "pointer",
			itemclick: function (e) {
				if (typeof (e.dataSeries.visible) === "undefined" || e.dataSeries.visible) {
					e.dataSeries.visible = false;
				}
				else {
					e.dataSeries.visible = true;
				}
				chart.render();
			}
		}
	});

	chart.render();
}