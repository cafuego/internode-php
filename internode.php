<?php
  /* internode.php
   * 
   * PHP classes and routines for retrieving, caching, formatting and displaying
   * Internode PADSL usage.
   *
   * Written by peter Lieverdink <me@cafuego.net>
   * Copyright 2004 Intellectual Property Holdings Pty. Ltd.
   *
   * License: GPL; See http://www.gnu.org/copyleft/gpl.html#SEC1 for a full version.
   *
   * Usage: http://yourwebhost.com.au/internode.php for the RSS feed
   *    or: http://yourwebhost.com.au/internode.php?DISPLAY=1 for the PNG image.
   *    or: http://yourwebhost.com.au/internode.php?DISPLAY=2 for text-only data.
   *    or: http://yourwebhost.com.au/internode.php?DISPLAY=3 for a raw data dump.
   *    or: http://yourwebhost.com.au/internode.php?DISPLAY=4 for a version check.
   *
   * Required software: php4 with gd and curl support.
   *
   * 19/05/2004 - Initial revision.
   *              The software fetches and caches usage stats. Then displays either
   *              an RSS feed or a PNG image with the complete usage history.
   * 26/05/2004 - Updates.
   *              The script now checks for availability of gd and curl and also
   *              notifies the user if it can't write to the cache file.
   * 30/08/2004 - New features, fixes.
   *              Use the long php open tag. 
   *              The script can now also display text-only data or a direct raw
   *              data dump. Version checking is supported. (Use DISPLAY=4)
   * 12/12/2004 - Added a days remaining counter and using this to generate averge
   *              Mb per day remaining graph and numbers - an idea by Andrew Brightman
   *              who never got back to me with his patch :-)
   * 01/02/2005 - Happy new year!
   *              Added detection of unlimited plans, added tuneable graph, added long-term
   *              average graph.
   */

  // Your username and password, change these.
  define("INTERNODE_USERNAME", "replace_with_your_username");
  define("INTERNODE_PASSWORD", "replace_with_your_password");

  // Graph area size, tweak if you really must.
  define("IMAGE_WIDTH", 550);
  define("IMAGE_HEIGHT", 350);
  
  // Number of recent days to graph data for. (0 = all)
  define("GRAPH_DAYS", 0);

  // Don't modify anything else!
  define("DISPLAY", INTERNODE_USAGE);

  define("INTERNODE_HOST", "accounts.internode.on.net");
  define("INTERNODE_URI", "/cgi-bin/padsl-usage");
  define("INTERNODE_LOGIN", "/cgi-bin/login");
  define("INTERNODE_CACHE", ini_get("upload_tmp_dir")."/internode.cache");

  define("INTERNODE_USAGE", 0);
  define("INTERNODE_HISTORY", 1);
  define("INTERNODE_TEXT", 2);
  define("INTERNODE_RAW", 3);
  define("INTERNODE_VERSION_CHECK", 4);
 
  define("IMAGE_BORDER", 10);
  define("IMAGE_BORDER_LEFT", 60);
  define("IMAGE_BORDER_BOTTOM", 40);

  define("INTERNODE_VERSION", "9");

  define("CAFUEGO_HOST", "www.cafuego.net");
  define("CAFUEGO_URI", "/internode-usage.php");

  class history {
    var $date = null;
    var $usage = 0;
    function history($str) {
      $arr = explode(" ", $str);
      $this->date = mktime(0, 0, 0, substr($arr[0], 2, 2), substr($arr[0], 4, 2), substr($arr[0], 0, 2));
      $this->usage = $this->floatval($arr[1]);
    }
    function floatval($strValue) {
      $floatValue = ereg_replace("(^[0-9]*)(\\.|,)([0-9]*)(.*)", "\\1.\\3", $strValue);
      if (!is_numeric($floatValue)) $floatValue = ereg_replace("(^[0-9]*)(.*)", "\\1", $strValue);
      if (!is_numeric($floatValue)) $floatValue = 0;
      return $floatValue;
    }
  }

  class internode {

    var $used = 0;
    var $quota = 0;
    var $remaining = 0;
    var $percentage = 0;
    var $history = null;
    var $error = null;
    var $days_remaining = 0;
    var $p_start = 0;
    var $p_end = 0;
    var $unlimited = false;

    function internode() {

      if(!file_exists(INTERNODE_CACHE))
        $this->refresh_cache();
      else if( filemtime(INTERNODE_CACHE) < (time() - 3600))
        $this->refresh_cache();

      $this->error = $this->read_cache();

    }

    function refresh_cache() {
      $usage = $this->fetch_data(INTERNODE_USAGE);
      $history = $this->fetch_data(INTERNODE_HISTORY);
      $fp = fopen(INTERNODE_CACHE, "w");
      if($fp) {
        fputs($fp, $usage);
        fputs($fp, $history);
        fclose($fp);
      }
    }

    function read_cache() {
      if($fp = fopen(INTERNODE_CACHE, "r") ) {
        $tmp = trim(fgetss($fp, 4096));
        $arr = explode(" ", $tmp);

	// Do a bit of half-arsed error checking.
	// 
        if(intval($arr[0] ) == 0)
	  return $tmp;

        $this->used = $arr[0];
        $this->quota = $arr[1];

	// See, if quota is set to '0', we have a lucky bastard on an
	// unlimited plan, which means we need not display remaining &c.
	if($this->quota > 0) {
          $this->remaining = $this->quota - $this->used;
          $this->percentage = 100 * $this->used / $this->quota;
	} else {
	  $this->unlimited = true;
	  $this->remaining = 0;
	  $this->percentage = 0;
	}
        $this->history = array();
	$this->p_start = $this->period_start($arr[2]);
	$this->p_end = $this->period_end($arr[2]);
	$this->days_remaining = $this->get_remaining_days($arr[2]);
	if(!$this->days_remaining)
	  $this->days_remaining = 1;
	while(!feof($fp)) {
	  if( ($str = trim(fgetss($fp, 4096))) != "") {
	    array_push($this->history, new history($str) );
	  }
	}
	fclose($fp);

	if(GRAPH_DAYS) {
          // Chop the history array.
	  $this->history = array_slice($this->history, (count($this->history) - GRAPH_DAYS) );
	}
      }
      return NULL;
    }

    function get_remaining_days($str) {
      list($y,$m,$d) = sscanf($str, "%04d%02d%02d");
      return intval( (strtotime( sprintf("%04d-%02d-%02d 00:00:00 +1000", $y, $m, $d)) - time()) / (60*60*24));
    }

    // the d++ and m-- calls work in php4 but this roll-over functionality MAY be removed in php5.
    function period_start($str) {
      list($y,$m,$d) = sscanf($str, "%04d%02d%02d");
      $m--;
      return strtotime( sprintf("%04d-%02d-%02d 00:00:00 +1000", $y, $m, $d));
    }

    function period_end($str) {
      list($y,$m,$d) = sscanf($str, "%04d%02d%02d");
      $d--;
      return strtotime( sprintf("%04d-%02d-%02d 00:00:00 +1000", $y, $m, $d));
    }

    function fetch_data($param) {
      $url = "https://".INTERNODE_HOST.INTERNODE_URI;

      $o = curl_init();

      curl_setopt($o, CURLOPT_URL, $url);
      curl_setopt($o, CURL_VERBOSE, 1);
      curl_setopt($o, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($o, CURLOPT_POST, 1);
      curl_setopt($o, CURLOPT_POSTFIELDS, $this->make_data($param) );
    
      curl_setopt($o, CURLOPT_USERAGENT, sprintf("internode.php v.%d; Copyright 2004 Intellectual Property Holdings Pty. Ltd.", INTERNODE_VERSION ) );
      curl_setopt($o, CURLOPT_SSL_VERIFYPEER, 0);
      curl_setopt($o, CURLOPT_SSL_VERIFYHOST, 2);
    
      $result = curl_exec($o); // run the whole process
    
      if(!$result)
        $result = "CURL Error ". curl_errno($o) .": ". curl_error($o);
    
      curl_close($o);
      return $result;
    }
    
    function make_data($param) {
      $ret = array(
        'username' => INTERNODE_USERNAME."@internode.on.net",
        'password' => INTERNODE_PASSWORD,
        'iso' => 1
      );
    
      if($param == INTERNODE_HISTORY)
        $ret['history'] = 1;
    
      return $ret;
    }

    function display($param) {
      switch($param) {
        case INTERNODE_HISTORY:
	  $this->display_history();
	  break;
        case INTERNODE_TEXT:
	  $this->display_text();
	  break;
        case INTERNODE_RAW:
	  $this->display_raw();
	  break;
        case INTERNODE_VERSION_CHECK:
	  $this->version_check();
	  break;
        default:
	  $this->display_rss();
	  break;
      }
    }

    function version_check() {
      echo @readfile("http://".CAFUEGO_HOST.CAFUEGO_URI."?version=".INTERNODE_VERSION);
    }

    function display_raw() {
      header("Content-type: text/plain");
      @readfile( INTERNODE_CACHE );
    }

    function display_text() {
      header("Content-type: text/plain");
      echo   "generator|Internode Usage v.". INTERNODE_VERSION ." - PHP ".phpversion()." ".strftime("%d/%m/%Y %H:%M:%S %Z")."\n";
      echo   "account|".INTERNODE_USERNAME."@internode.on.net\n";
      printf("used|%.2f Gb\n", $this->used/1000 );
      if(!$this->unlimited) {
        printf("quota|%.2f Gb\n", $this->quota/1000 );
        printf("remaining|%.2f Gb\n", $this->remaining/1000 );
        printf("percentage|%.2f Gb\n", $this->percentage );
        printf("remaining per day|%.2f Mb\n", ($this->remaining / $this->days_remaining) );
      }
    }

    function display_rss() {
      header("Content-type: text/xml");
      echo "<?xml version=\"1.0\"?>\n";
      echo "<!-- RSS generated by Internode Usage v.". INTERNODE_VERSION ." - PHP ".phpversion()." ".strftime("%d/%m/%Y %H:%M:%S %Z")." -->\n";
      echo "<rss version=\"2.0\" xmlns:blogChannel=\"http://backend.userland.com/blogChannelModule\">\n";
      echo "<channel>\n";
      echo "<title>Internode ADSL Usage</title>\n";
      echo "<link>https://".INTERNODE_HOST.INTERNODE_LOGIN."</link>\n";
      echo "<description>Internode ADSL Usage for ".INTERNODE_USERNAME."@internode.on.net</description>\n";
      echo "<language>en-au</language>\n";
      echo "<copyright>Copyright 2004 Intellectual Property Holdings Pty. Ltd.</copyright>\n";
      echo "<docs></docs>\n";
      echo "<generator>Internode Usage v.". INTERNODE_VERSION ." - PHP ".phpversion()."</generator>\n";
      echo "<managingEditor>".INTERNODE_USERNAME."@internode.on.net</managingEditor>\n";
      echo "<webMaster>webmaster@internode.on.net</webMaster>\n";
      echo "<ttl>3600</ttl>\n";
      echo "<item>\n";
      printf("  <title>Used: %.2f Gb</title>\n", $this->used/1000 );
      echo "</item>\n";
      if(!$this->unlimited) {
        echo "<item>\n";
        printf("  <title>Quota: %d Gb</title>\n", $this->quota/1000 );
        echo "</item>\n";
        echo "<item>\n";
        printf("  <title>Remaining: %.2f Gb</title>\n", $this->remaining/1000 );
        echo "</item>\n";
        echo "<item>\n";
        printf("  <title>Percentage: %.2f %% </title>\n", $this->percentage );
        echo "</item>\n";
        echo "<item>\n";
        printf("  <title>Remaining per day: %.2f Mb</title>\n", ($this->remaining / $this->days_remaining) );
        echo "</item>\n";
      }
      echo "</channel>\n";
      echo "</rss>\n";
    }

    function display_history() {
      if(!function_exists("imagepng")) {
        die("Sorry, this PHP installation cannot create dynamic PNG images");
      }
    
      header("Content-type: image/png");
      header("Content-disposition: inline; filename=Internode_Usage_Graph.png");

      // Create image of specified size (and leave space for the borders)
      //
      $im = imagecreate(IMAGE_WIDTH + (2*IMAGE_BORDER) + IMAGE_BORDER_LEFT, IMAGE_HEIGHT + (2*IMAGE_BORDER) + IMAGE_BORDER_BOTTOM);

      // Allocate some colours.
      //
      $white = imagecolorallocate($im, 255,255,255);
      $black = imagecolorallocate($im, 0,0,0);
      $red = imagecolorallocate($im, 224,0,0);
      $green = imagecolorallocate($im, 0,204,0);
      $darkgreen = imagecolorallocate($im, 0, 102,0);
      $blue = imagecolorallocate($im, 0,0,204);
      $orange = imagecolorallocate($im, 153,153,0);
      $purple = imagecolorallocate($im, 204,0,204);
      $yellow = imagecolorallocate($im, 255,255,0);

      // Draw three dashed background lines.
      //
      $dy = (IMAGE_HEIGHT-(2*IMAGE_BORDER)-IMAGE_BORDER_BOTTOM)/4;
      for($i = 1; $i < 4; $i++) {
        imagedashedline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER, IMAGE_BORDER+($i*$dy), IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, IMAGE_BORDER+($i*$dy), $black);
      }

      // Calculate bar width.
      //
      $dx = intval(IMAGE_WIDTH / (count($this->history)+1) );

      // Find scale maximum.
      //
      for($i = 0; $i < count($this->history); $i++) {
        if($this->history[$i]->usage > $max)
          $max = $this->history[$i]->usage;
        $total += $this->history[$i]->usage;
      }

      // Find where we need to right-align the y axis.
      //
      $len_max = imagefontwidth(2) * (1+(strlen(sprintf("%.1f Mb", $max))));
      $len_mmt = imagefontwidth(2) * (1+(strlen(sprintf("%.1f Mb", ($max*3/4)))));
      $len_med = imagefontwidth(2) * (1+(strlen(sprintf("%.1f Mb", ($max/2)))));
      $len_mmb = imagefontwidth(2) * (1+(strlen(sprintf("%.1f Mb", ($max/4)))));
      $len_min = imagefontwidth(2) * (1+(strlen("0.0 Mb")));
      $len_date = imagefontwidth(2) * (1+(strlen( strftime("%d %b %y", $this->history[count($this->history)]->date))));

      // Draw scale figures on y axis.
      //
      imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER-$len_max, IMAGE_BORDER-(imagefontheight(2)/2), sprintf("%.1f Mb", $max), $black);
      imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER-$len_mmt, IMAGE_BORDER+$dy-(imagefontheight(2)/2), sprintf("%.1f Mb", ($max*3/4)), $black);
      imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER-$len_med, IMAGE_BORDER+(2*$dy)-(imagefontheight(2)/2), sprintf("%.1f Mb", ($max/2)), $black);
      imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER-$len_mmb, IMAGE_BORDER+(3*$dy)-(imagefontheight(2)/2), sprintf("%.1f Mb", ($max/4)), $black);
      imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER-$len_min, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-(imagefontheight(2)/2), "0.0 Mb", $black);

      // Find out the interval for x axis labels.
      //
      $mod = intval(count($this->history)/8);

      // Draw usage bars and x axis.
      // When usage is NEGATIVE, draw bar UP anyway but in yellow.
      //
      imagesetthickness($im, 2);

      $prev_avg_w = 0;
      $prev_avg_m = 0;
      for($i = 0; $i < count($this->history); $i++) {
	if($this->history[$i]->usage > 0) {
	  $y = $this->history[$i]->usage * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
          imagefilledrectangle($im, IMAGE_BORDER_LEFT+IMAGE_BORDER+($i*$dx), (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$y), IMAGE_BORDER_LEFT+IMAGE_BORDER+($i*$dx)+$dx, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER, $green);
	} else { 
	  $y = (abs($this->history[$i]->usage)) * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
          imagefilledrectangle($im, IMAGE_BORDER_LEFT+IMAGE_BORDER+($i*$dx), (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$y), IMAGE_BORDER_LEFT+IMAGE_BORDER+($i*$dx)+$dx, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER, $orange);
	}
	if($i % $mod == 0)
          imagestringup($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+($i*$dx)-(imagefontheight(2)/2)+($dx/2), IMAGE_HEIGHT-IMAGE_BORDER-IMAGE_BORDER_BOTTOM+$len_date, strftime("%d %b %y", $this->history[$i]->date), $black);

	// Add weekly moving average.
	if($i > 0) {
  	  for($j = ($i-3); $j <= ($i+3); $j++) {
	    if( $this->history[$j] ) {
              $avg_w += abs($this->history[$j]->usage);
	      $k_w++;
	    }
	  }
	  $avg_w /= $k_w;
	  $avg_w_y = $avg_w * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
	  $prev_avg_w_y = $prev_avg_w * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
	  imageline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER+(($i-0.5)*$dx), (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$prev_avg_w_y),  IMAGE_BORDER_LEFT+IMAGE_BORDER+(($i+0.5)*$dx), (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$avg_w_y), $purple);
	  $prev_avg_w = $avg_w;
	  $avg_w = 0;
	  $k_w = 0;
	}

	// Add quarterly moving average.
	if($i > 0) {
  	  for($j = ($i-44); $j <= ($i+44); $j++) {
	    if( $this->history[$j] ) {
              $avg_m += abs($this->history[$j]->usage);
	      $k_m++;
	    }
	  }
	  $avg_m /= $k_m;
	  $avg_m_y = $avg_m * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
	  $prev_avg_m_y = $prev_avg_m * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
	  imageline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER+(($i-0.5)*$dx), (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$prev_avg_m_y),  IMAGE_BORDER_LEFT+IMAGE_BORDER+(($i+0.5)*$dx), (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$avg_m_y), $red);
	  $prev_avg_m = $avg_m;
	  $avg_m = 0;
	  $k_m = 0;
	}
      }

      imagesetthickness($im, 1);

      // Add overall average.
      $y = ($total / count($this->history)) * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
      imagedashedline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER, (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$y), IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$y), $blue);

      // Add remaining daily average.
      $y = ($this->remaining/$this->days_remaining) * (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-(2*IMAGE_BORDER)) / $max;
      imagedashedline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER, (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$y), IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, (IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER-$y), $orange);

      // Add some info/legend.
      $string = $string = sprintf("Current period: %s - %s", strftime("%a %d %b %Y", $this->p_start), strftime("%a %d %b %Y", $this->p_end) );
      imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 1), $string, $black);

      if(!$this->unlimited) {
        $string = $string = sprintf("Graph Interval: %d days   Remaining: %d days", count($this->history), $this->days_remaining);
        imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 2), $string, $blue);

        $string = sprintf("Daily Transfer: %.1f Mb   Total Transfer: %.1f Gb", ($total / count($this->history)), $total/1000);
        imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 3), $string, $darkgreen);

	if($this->remaining > 0) {
          $string = sprintf("Daily Remaining: %.1f Mb   Total Remaining: %.1f Gb", ($this->remaining / $this->days_remaining), ($this->remaining/1000) );
          imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 4), $string, $orange);
	} else {
	  // Whoops, over quota! ;-)
          $string = sprintf("WARNING: You are %.1f Gb over quota!", abs($this->remaining/1000) );
          imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 4), $string, $red);
	}
      } else {
        $string = $string = sprintf("Graph Interval: %d days", count($this->history));
        imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 2), $string, $blue);

        $string = sprintf("Daily Transfer: %.1f Mb   Total Transfer: %.1f Gb", ($total / count($this->history)), $total/1000);
        imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 3), $string, $darkgreen);
      }

      // $string = sprintf("Graph: %d days   Daily Average: %.1f Mb   Total: %.1f Gb", count($this->history), ($total / count($this->history)), $total/1000);
      // imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), imagefontheight(2), $string, $blue);

      // $string = sprintf("Remaining: %d days   Daily Remaining: %.1f Mb   Total Remaining: %.1f Gb", ($this->days_remaining), ($this->remaining / $this->days_remaining), ($this->remaining/1000) );
      // imagestring($im, 2, IMAGE_BORDER_LEFT+IMAGE_BORDER+imagefontwidth(2), (imagefontheight(2) * 2), $string, $blue);

      // Draw 0-max border around the graph.
      //
      imageline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER, IMAGE_BORDER, IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, IMAGE_BORDER, $black);
      imageline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER, IMAGE_BORDER, IMAGE_BORDER_LEFT+IMAGE_BORDER, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER, $black); 
      imageline($im, IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, IMAGE_BORDER, IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER, $black); 
      imageline($im, IMAGE_BORDER_LEFT+IMAGE_BORDER, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER, IMAGE_WIDTH+IMAGE_BORDER_LEFT-IMAGE_BORDER, IMAGE_HEIGHT-IMAGE_BORDER_BOTTOM-IMAGE_BORDER, $black);

      // And now just add a footer.
      //
      $footer = sprintf("PADSL usage graph %s - %s for %s@internode.on.net", strftime("%d/%m/%Y", $this->history[0]->date), strftime("%d/%m/%Y", $this->history[count($this->history)-1]->date), INTERNODE_USERNAME );
      imagestring($im, 3, (IMAGE_BORDER_LEFT+IMAGE_WIDTH+(2*IMAGE_BORDER))/2 - imagefontwidth(3) * (strlen($footer)/2), IMAGE_HEIGHT+IMAGE_BORDER_BOTTOM-IMAGE_BORDER, $footer, $black);

      $copyright = sprintf("Generated by internode.php v.%d - Copyright 2004 Intellectual Property Holdings Pty. Ltd.", INTERNODE_VERSION );
      imagestring($im, 1, (IMAGE_BORDER_LEFT+IMAGE_WIDTH+(2*IMAGE_BORDER))/2 - imagefontwidth(1) * (strlen($copyright)/2), IMAGE_HEIGHT+IMAGE_BORDER_BOTTOM+IMAGE_BORDER, $copyright, $black);

      // Output image and deallocate memory.
      //
      imagepng($im);
      unset($im);
    }
  }

  // Check installation options.
  //
  if(!function_exists('curl_init'))
    die("Your PHP installation is missing the CURL extension.");
  if(!CURLOPT_SSLVERSION)
    die("Your CURL version does not have SSL support enabled.");
  if(!file_exists(INTERNODE_CACHE)) {
    if(!$fp = @fopen(INTERNODE_CACHE, "w")) {
      die("Cannot create cache file '".INTERNODE_CACHE."'.\n\nPlease set upload_tmp_dir to a directory with mode 1777 in your php.ini");
    } else {
      @unlink(INTERNODE_CACHE);
    }
  } else {
    if(!is_writable(INTERNODE_CACHE)) {
      die("Cannot write data to cache file '".INTERNODE_CACHE."'.\n\nPlease set upload_tmp_dir to a directory with mode 1777 in your php.ini");
    }
  }

  $in = new internode();

  // See if the stats server mentioned any errors
  if($in->error) {
    echo "Oops! Something unexpected happened, ".INTERNODE_HOST." said: &quot;<b style=\"color: #f00\">{$in->error}</b>&quot;\n";
  } else {
    $in->display( intval($_GET['DISPLAY']) );
  }

  /* No llamas were harmed during the creation of this tool */
?>
