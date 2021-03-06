<?php

function create_ldap_account($vars) {
	$ds = ldap_connect("localhost");  // assuming the LDAP server is on this host

	if ($ds) {
		// bind with appropriate dn to give update access
		ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);
		$r = ldap_bind($ds, "cn=admin,dc=yourdomain,dc=com", "secret");

		// variables from WHMCS
		$userid = $vars['userid'];

		// query database for username
		$table = "tblcustomfieldsvalues";
		$fields = "fieldid,relid,value";
		$where = array("fieldid"=>"2", "relid"=>$userid);
		$result = select_query($table,$fields,$where);
		$data = mysql_fetch_array($result);
		$username = $data['value'];
		logActivity($username);

		$firstname = $vars['firstname'];
		$lastname = $vars['lastname'];
		$email = $vars['email'];
		$address = $vars['address1'] . " " . $vars['address2'];
		$postalcode = $vars['postcode'];
		$state = $vars['state'];
		$city = $vars['city'];
		$password = $vars['password'];
		$phonenumber = $vars['phonenumber'];


		// shadowlastchange requires days since last epoch
		$unixTimeDays = floor(time()/86400);

		// function will find the largest UID, then add 1 to generate a UID for the user
		// http://bakery.cakephp.org/articles/UncleBill/2006/10/15/using-ldap-as-a-database
		function findLargestUidNumber($ds)
		{
		  $s = ldap_search($ds, "ou=people,dc=yourdomain,dc=com", 'uidnumber=*');
		  if ($s)
		  {
			 // there must be a better way to get the largest uidnumber, but I can't find a way to reverse sort.
			 ldap_sort($ds, $s, "uidnumber");

			 $result = ldap_get_entries($ds, $s);
			 $count = $result['count'];
			 $biguid = $result[$count-1]['uidnumber'][0];
			 return $biguid;
		  }
		  return null;
		}
		$largestUID = findLargestUidNumber($ds);
		if ($largestUID == null)
		{
			logActivity("Unable to find largest UID");
		}
		else {
			$generatedUID = $largestUID + 1;

			// construct array of information which will be added to LDAP
			$info['cn'][0] = $firstname;
			$info['description'][0] = "User account";
			$info['displayName'][0] = $firstname . " " . $lastname;
			$info['gecos'] = $username;
			$info['sn'][0] = $lastname;
			$info['mail'][0] = $email;
			$info['objectclass'][0] = "inetOrgPerson";
			$info['objectclass'][1] = "posixAccount";
			$info['objectclass'][2] = "shadowAccount";
			$info['gidnumber'][0] = "10000";
			$info['homedirectory'][0] = "/home/" . $username;
			$info['homephone'][0] = $phonenumber;
			$info['l'][0] = $city;
			$info['loginshell'][0] = "/bin/bash";
			$info['mobile'][0] = $phonenumber;
			$info['postalcode'][0] = $postalcode;
			$info['shadowlastchange'][0] = $unixTimeDays;
			$info['st'][0] = $state;
			$info['street'][0] = (string) $address;
			$info['title'][0] = "Member";
			$info['uid'][0] = $username;
			$info['uidnumber'][0] = $generatedUID;

			// Generate SSHA hash
			mt_srand((double)microtime()*1000000);
			$salt = pack("CCCC", mt_rand(), mt_rand(), mt_rand(), mt_rand());
			$info['userpassword'][0] = "{SSHA}" . base64_encode(pack("H*", sha1($password . $salt)) . $salt);

			$dn = "uid=" . $username . ",ou=people,dc=yourdomain,dc=com";

			// add data to people OU
			$r = ldap_add($ds, $dn, $info);
			if (!$r)
			{
				logActivity(ldap_error($ds));
			}

			// add user to members group
			$group_name = "cn=members,ou=groups,dc=yourdomain,dc=com";
			$group_info['memberUid'] = $username;
			$t = ldap_mod_add($ds,$group_name,$group_info);

			if (!$t)
			{
				logActivity(ldap_error($ds));
			}
		}
		ldap_close($ds);
	} else {
			logActivity("Unable to connect to LDAP server");
	}
}

add_hook("ClientAdd",1,"create_ldap_account");

?>
