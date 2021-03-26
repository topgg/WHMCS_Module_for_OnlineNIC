Installation guide:

1.  Create a folder namded 'OnlineNICPro' under path :   your_public_html*****/whmcs/modules/registrars/ 
     
2. Extract the zip file to  whmcs/modules/registrars/OnlineNICPro    !Case sensitive! Make sure the file name and path are correct.

NOTE! We use port 30009, if you have set output rules in iptables , make sure you have add our server IP and port to exception.

* WHMCS 6.x WHMCS 7.x
* PHP 5.3+
* Adjust your firewall settings like below to allow your server establish connection with OnlineNIC API server Port 30009
Firewall Settings:
iptables -A  OUTPUT -p tcp --dport 30009 -j ACCEPT
iptables -A  INPUT -p tcp --sport 30009 -j ACCEPT

Change Log

#########Module Version 1.4beta####### OCT 16th 2017##############
#########What's new?#########                          
#1.none
########Bug fixed#########
#1.Incomplete information when retriving XML from API server #Fixed by chenwp
#2.Not able to save registrant contact #Luke
#known bugs:                           
#UnKnown
#########Module Version 1.3####### AUG 11th 2017#######Luke#######
#########what's new?##########
#1.Added Module Logging    #Luke 
#2.Fixed a Bug in SavecontactDetails   # Luke
#3.Fix bug and support Uk Registration/Renewal/management # Luke
#History Changelog：                                       
#########known BUG:########### 
#1.Incomplete information when retriving XML from API server
#NONE             
#######Module V1.2 ####### July 11th 2017#######Luke#######
##########what's new?#########
#Removed logout 
#1.Delete domain
#2.bug fixed    
##########Known bugs##########
#1.cannot register .co.uk ， we can create .uk domain contact but cannot register. 
#2.Incomplete information when retriving XML from API server