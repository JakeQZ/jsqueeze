<?php /*********************************************************************
 *
 *   Copyright : (C) 2006 Nicolas Grekas. All rights reserved.
 *   Email     : nicolas.grekas+patchwork@espci.org
 *   License   : http://www.gnu.org/licenses/gpl.txt GNU/GPL, see COPYING
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/


/*
* This class obfuscates javascript code
*
* Every var whose name/method begins with one or more "$" or a single "_" will be replaced by a shorter one, typicaly a single letter.
* Comments will be removed
* White chars will be stripped
*
* Works with most valid Javascript code as long as semicolons are here.
* If you use with/eval then be careful.
*/

class jsqueez
{
	function jsqueez()
	{
		$this->known = array();
		$this->data = array();
		$this->counter = 0;
		$this->varRx = '(?<![a-zA-Z0-9_\$])(?:\$+[a-zA-Z_]|_[a-zA-Z0-9\$])[a-zA-Z0-9_\$]*';
	}

	function addJs($code) {$this->data[] =& $code;}

	function get()
	{
		$code = implode("\n", $this->data);

		if (false !== strpos($code, "\r")) $code = strtr(str_replace("\r\n", "\n", $code), "\r", "\n");

		list($code, $this->strings) = $this->extractStrings($code);

		list($code, $this->closures) = $this->extractClosures($code);

		$key = "//''\"\"f0'";
		$this->closures[$key] =& $code;

		$tree = array($key => array('parent' => false));
		$this->makeVars($code, $tree[$key]);

		$this->renameVars($tree[$key]);

		$code = substr($tree[$key]['code'], 1);
		$code = str_replace(array_keys($this->strings), array_values($this->strings), $code);

		return $code;
	}

	function extractStrings($f)
	{
		$f = trim($f);

		if ('' === $f) return array('', array());

		if ($cc_on = false !== strpos($f, '@cc_on'))
		{
			$f = str_replace('#', '##', $f);
			$f = preg_replace("'/*@cc_on(?![\$\.a-zA-Z0-9_])'", '1#@cc_on', $f);
			$f = preg_replace("'//@cc_on(?![\$\.a-zA-Z0-9_])'", '2#@cc_on', $f);
			$f = str_replace('@*/', '@#1', $f);
		}

		$code = array();
		$j = 0;

		$strings = array();
		$K = 0;

		$instr = false;

		$len = strlen($f);
		for ($i = 0; $i < $len; ++$i)
		{
			if ($instr)
			{
				if ('//' == $instr)
				{
					if ("\n" == $f[$i]) $instr = false;
				}
				else if ($f[$i] == $instr)
				{
					if ('*' == $instr)
					{
						if ('/' == $f[$i+1])
						{
							++$i;
							$instr = false;
						}
					}
					else
					{
						if ('/' == $instr) while (false !== strpos('gmi', $f[$i+1])) $s[] = $f[$i++];
						$instr = false;
						$s[] = $f[$i];
					}
				}
				else if ('*' == $instr) ;
				else if ('\\' == $f[$i])
				{
					if ("\n" == $f[$i+1]) ++$i;
					else
					{
						$s[] = $f[$i];
						++$i;
						$s[] = $f[$i];
					}
				}
				else $s[] = $f[$i];
			}
			else switch ($f[$i])
			{
			case '/':
				if ('*' == $f[$i+1])
				{
					++$i;
					$instr = '*';
				}
				else if ('/' == $f[$i+1])
				{
					++$i;
					$instr = '//';
				}
				else
				{
					$a = ' ' == $code[$j] ? $code[$j-1] : $code[$j];
					if (false !== strpos('-!%&;<=>~:^+|,(*?[{n', $a))
					{
						$key = "//''\"\"" . $K++ . $instr = $f[$i];
						$code[++$j] = $key;
						isset($s) && ($s = implode('', $s)) && $cc_on && $this->_restoreCc($s);
						$strings[$key] = array($instr);
						$s =& $strings[$key];
					}
					else $code[++$j] = $f[$i];
				}

				break;

			case "'":
			case '"':
				$key = "//''\"\"" . $K++ . $instr = $f[$i];
				$code[++$j] = $key;
				isset($s) && ($s = implode('', $s)) && $cc_on && $this->_restoreCc($s);
				$strings[$key] = array($instr);
				$s =& $strings[$key];
				break;

			case "\n":
				break;

			case "\t": $f[$i] = ' ';
			case ' ':
				if (' ' == $code[$j]) break;

			default:
				$code[++$j] = $f[$i];
			}
		}

		isset($s) && ($s = implode('', $s)) && $cc_on && $this->_restoreCc($s);
		unset($s);

		$code = implode('', $code);
		$cc_on && $this->_restoreCc($code);

		$code = str_replace('- -', '-#-', $code);
		$code = str_replace('+ +', '+#+', $code);
		$code = preg_replace("' ?([-!%&;<=>~:\\/\\^\\+\\|\\,\\(\\)\\*\\?\\[\\]\\{\\}]+) ?'", '$1', $code);
		$code = str_replace('-#-', '- -', $code);
		$code = str_replace('+#+', '+ +', $code);

		false !== strpos($code, 'new Array' ) && $code = preg_replace( "'new Array(?=(\(\)|[;\]\)\},:]))'", '[]', $code);
		false !== strpos($code, 'new Object') && $code = preg_replace("'new Object(?=(\(\)|[;\]\)\},:]))'", '{}', $code);

		$code = preg_replace("'\}(?![:,;\.\(\)\]\}]|(else|catch|finally|while)[^\$\.a-zA-Z0-9_])'", '};', $code);

		$code = preg_replace("'(?<![\$\.a-zA-Z0-9_])if\('"                    , '1#(', $code);
		$code = preg_replace("'(?<![\$\.a-zA-Z0-9_])for\('"                   , '2#(', $code);
		$code = preg_replace("'(?<![\$\.a-zA-Z0-9_])while\('"                 , '3#(', $code);
		$code = preg_replace("'(?<![\$\.a-zA-Z0-9_])else(?![\$\.a-zA-Z0-9_])'", '4#' , $code);

		$forPool = array();
		$instrPool = array();
		$s = 0;

		$f = array();
		$j = -1;

		$len = strlen($code);
		for ($i = 0; $i < $len; ++$i)
		{
			switch ($code[$i])
			{
			case '(':
				if ("\n" == $f[$j]) $f[$j] = ';';

				++$s;

				if ($i && '#' == $code[$i-1])
				{
					$instrPool[$s - 1] = 1;
					if ('2' == $code[$i-2]) $forPool[$s] = 1;
				}

				$f[++$j] = '(';
				break;

			case ')':
				unset($forPool[$s]);
				--$s;
				$f[++$j] = ')';
				continue 2;

			case '}':
				if ("\n" == $f[$j]) $f[$j] = '}';
				else $f[++$j] = '}';
				break;

			case ';':
				if (isset($forPool[$s]) || isset($instrPool[$s])) $f[++$j] = ';';
				else if ("\n" != $f[$j]) $f[++$j] = "\n";

				break;

			case '#':
				switch ($f[$j])
				{
				case '1': $f[$j] = 'if';    break 2;
				case '2': $f[$j] = 'for';   break 2;
				case '3': $f[$j] = 'while'; break 2;
				case '4': $f[$j] = 'else';  continue 2;
				}

			case '[';
				if ("\n" == $f[$j]) $f[$j] = ';';

			default: $f[++$j] = $code[$i];
			}

			unset($instrPool[$s]);
		}

		return array(implode('', $f), $strings);
	}

	function extractClosures($code)
	{
		$code = ';' . $code;

		$this->known = preg_match_all("'\.([a-z][a-z0-9_\$]*)'i", $code, $i) ? $i[1] : array();

		$f = preg_split("'([^\$\.a-zA-Z0-9_]function[ \(].*?\{)'", $code, -1, PREG_SPLIT_DELIM_CAPTURE);
		$i = count($f)-1;
		$closures = array();

		while ($i)
		{
			$c = 1;
			$j = 0;
			$l = strlen($f[$i]);
			$fK = "//''\"\"f$i'";
			$fS = $f[$i-1];

			while ($c && $j<$l)
			{
				$fS .= $s = $f[$i][$j];
				$c += '{' == $s ? 1 : ('}' == $s ? -1 : 0);
				++$j;
			}

			$closures[$fK] = $fS;

			$f[$i-2] .= $fK . substr($f[$i], $j);
			$i -= 2;
		}

		if (preg_match_all("'[^a-z0-9_\$\"]([a-z][a-z0-9_\$]*)'i", $f[0], $i)) $this->known = array_merge($this->known, $i[1]);

		$this->known = array_flip($this->known);

		return array($f[0], $closures);
	}

	function makeVars($closure, &$tree)
	{
		$tree['code'] = $closure;

		# Get all local vars (arguments and "var" prefixed)
		$tree['local'] = $this->getVars($closure);

		# Get all used vars, local and non-local
		$tree['used'] = array();
		$a = preg_replace("'^.function {$this->varRx}\('", '', $closure);
		if (preg_match_all("#\.?{$this->varRx}#", $a, $w))
		{
			foreach ($w[0] as $k) @++$tree['used'][$k];
		}

		if (preg_match_all("#//''\"\"f\d+'#", $closure, $w))
		{
			foreach ($w[0] as $a)
			{
				if (preg_match("'^.function ({$this->varRx})\('", $this->closures[$a], $w))
				{
					$tree['local'][$w[1]] = 0;
					@++$tree['used'][$w[1]];
				}
			}
		}

		foreach ($this->strings as $a)
		{
			if (("'" == $a[0] || '"' == $a[0]) && preg_match_all("#\.?{$this->varRx}#", $a, $w))
			{
				foreach ($w[0] as $k) isset($tree['used'][$k]) && ++$tree['used'][$k];
			}
		}


		# Propagate the usage number to parents
		foreach ($tree['used'] as $w => $a)
		{
			$k =& $tree;
			$chain = array();
			do
			{
				$chain[] =& $k;
				if (isset($k['local'][$w]))
				{
					unset($k['used'][$w]);
					if (isset($k['local'][$w])) $k['local'][$w] += $a;
					else $k['local'][$w] = $a;
					$a = false;
					break;
				}
			}
			while ($k['parent'] && $k =& $k['parent']);

			if ($a && !$k['parent'])
			{
				if (isset($k['local'][$w])) $k['local'][$w] += $a;
				else $k['local'][$w] = $a;
			}

			if (isset($tree['used'][$w]) && isset($k['local'][$w])) foreach ($chain as $a => $b)
			{
				if (!isset($chain[$a]['local'][$w])) $chain[$a]['used'][$w] =& $k['local'][$w];
			}
		}

		# Analyse childs
		$tree['childs'] = array();
		if (preg_match_all("#//''\"\"f\d+'#", $closure, $w))
		{
			foreach ($w[0] as $a)
			{
				$tree['childs'][$a] = array('parent' => &$tree);
				$this->makeVars($this->closures[$a], $tree['childs'][$a]);
			}
		}
	}

	function renameVars(&$tree, $base = true)
	{
		$this->_getNextName(true);

		if ($base)
		{
			foreach (array_keys($tree['local']) as $var)
			{
				if ('.' != substr($var, 0, 1) && isset($tree['local'][".{$var}"])) $tree['local'][$var] += $tree['local'][".{$var}"];
			}

			foreach (array_keys($tree['local']) as $var)
			{
				if ('.' == substr($var, 0, 1) && isset($tree['local'][substr($var, 1)])) $tree['local'][$var] = $tree['local'][substr($var, 1)];
			}

			arsort($tree['local']);

			foreach (array_keys($tree['local']) as $var) switch (substr($var, 0, 1))
			{
			case '.':
				if (!isset($tree['local'][substr($var, 1)]))
				{
					$tree['local'][$var] = '#' . $this->_getNextName(array_flip($tree['used']));
				}
				break;

			case '#': break;

			default:
				$base = $this->_getNextName(array_flip($tree['used']));
				$tree['local'][$var] = $base;
				if (isset($tree['local'][".{$var}"])) $tree['local'][".{$var}"] = '#' . $base;
			}

			foreach (array_keys($tree['local']) as $var) $tree['local'][$var] = preg_replace("'^#'", '.', $tree['local'][$var]);
		}
		else
		{
			arsort($tree['local']);

			foreach (array_keys($tree['local']) as $var)
			{
				$tree['local'][$var] = $this->_getNextName(array_flip($tree['used']));
			}
		}

		foreach (array_keys($tree['childs']) as $var)
		{
			$this->renameVars($tree['childs'][$var], false);
			$tree['code'] = str_replace($var, $tree['childs'][$var]['code'], $tree['code']);
		}

		$tree['code'] = preg_replace("#\.?{$this->varRx}#e", "isset(\$tree['local']['$0']) ? \$tree['local']['$0'] : '$0'", $tree['code']);


		$this->local_tree =& $tree['local'];

		preg_replace_callback("#//''\"\"[0-9]+['\"]#", array($this, 'renameInString'), $tree['code']);
	}

	function renameInString($a)
	{
		$a = $a[0];
		$tree =& $this->local_tree;

		$this->strings[$a] = preg_replace("#\.?{$this->varRx}#e", "isset(\$tree['$0']) ? \$tree['$0'] : '$0'", $this->strings[$a]);

		return '';
	}

	function getVars($closure)
	{
		$vars = array();

		if (preg_match("'\((.*?)\)'i", $closure, $v))
		{
			$v = explode(',', $v[1]);
			foreach ($v as $w) if (preg_match("'^{$this->varRx}$'", $w)) $vars[$w] = 0;
		}

		if (preg_match_all("'[^\$\.a-zA-Z0-9_]var ([^;]+)'i", $closure, $v))
		{
			$v = implode(',', $v[1]);

			$v = preg_replace("'\(.*?\)'", '', $v);
			$v = preg_replace("'\{.*?\}'", '', $v);
			$v = preg_replace("'\[.*?\]'", '', $v);
			$v = explode(',', $v);
			foreach ($v as $w) if (preg_match("'^{$this->varRx}'", $w, $v)) $vars[$v[0]] = 0;
		}

		return $vars;
	}

	function _getNextName($exclude = array())
	{
		if ($exclude===true) return $this->counter = -1;
		else ++$this->counter;

		$str0 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$len0 = strlen($str0);

		$str1 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$len1 = strlen($str1);

		$name = $str0[$this->counter % $len0];

		$i = intval($this->counter / $len0) - 1;
		while ($i>=0)
		{
			$name .= $str1[ $i % $len1 ];
			$i = intval($i / $len1) - 1;
		}

		return !(isset($this->known[$name]) || isset($exclude[$name])) ? $name : $this->_getNextName($exclude);
	}

	function _restoreCc(&$s)
	{
		$s = str_replace('@#1', '@*/', $s);
		$s = str_replace('2#@cc_on', '//@cc_on', $s);
		$s = str_replace('1#@cc_on', '/*@cc_on', $s);
		$s = str_replace('##', '#', $s);
	}
}