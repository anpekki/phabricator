Forked from https://github/phacility/phabricator.git to provide RPM packaging
for RHEL/Centos 6.

Howto build:
* yum install rpm-build git
* git clone https://github/vinzent/phabricator.git
* cd phabricator
* rpmbuild -bb resources/rhel/phabricator.spec

3 RPM's are built:
* phabricator: webapps
* phabricator-arcanist: "arc" tool
* phabricator-libphutil: libphutil php lib

Install:
* yum localinstall ~/rpmbuild/RPMS/noarch/phabricator-*.rpm
* /opt/phacility/phabricator/bin/config set mysql.user dbuser
* /opt/phacility/phabricator/bin/config set mysql.pass dbpass
* /opt/phacility/phabricator/bin/storage upgrade
* service phabricator start
* cp resources/rhel/phabricator.httpd.conf /etc/httpd/conf.d/phabricator.conf
* service httpd restart

Upgrade:
* service httpd stop
* service phabricator stop
* yum localinstall ~/rpmbuild/RPMS/noarch/phabricator-*.rpm
* /opt/phacility/phabricator/bin/storage upgrade
* service phabricator start
* service httpd start

Original README:

**Phabricator** is a collection of web applications which help software companies build better software.

Phabricator includes applications for:

  - reviewing and auditing source code;
  - hosting and browsing repositories;
  - tracking bugs;
  - managing projects;
  - conversing with team members;
  - assembling a party to venture forth;
  - writing stuff down and reading it later;
  - hiding stuff from coworkers; and
  - also some other things.

You can learn more about the project (and find links to documentation and resources) at [Phabricator.org](http://phabricator.org)

Phabricator is developed and maintained by [Phacility](http://phacility.com).

----------

**SUPPORT RESOURCES**

For resources on filing bugs, requesting features, reporting security issues, and getting other kinds of support, see [Support Resources](https://secure.phabricator.com/book/phabricator/article/support/).

**NO PULL REQUESTS!**

We do not accept pull requests through GitHub. If you would like to contribute code, please read our [Contributor's Guide](https://secure.phabricator.com/book/phabcontrib/article/contributing_code/).

**LICENSE**

Phabricator is released under the Apache 2.0 license except as otherwise noted.
