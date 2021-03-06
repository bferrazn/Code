/**
 * This contains most of the equivalent functionality to the LigminchaGlobalDistributed PHP class
 */

'use strict';

var LG_SUCCESS  = 'ok';
var LG_ERROR    = 'error';

var LG_LOG      = 1;
var LG_SERVER   = 2;
var LG_USER     = 3;
var LG_SESSION  = 4;
var LG_SYNC     = 5;
var LG_VERSION  = 6;
var LG_DATABASE = 7;

// The app is all contained in this object
window.lg = {
	classes: [0, 'Log', 'Server', 'User', 'Session', 'Sync', 'Version', 'Database']
};

(function($, lg) {

	// Return the reference to an objects model given its GUID
	// TODO: we should maintain indexes for the main parameters for this method and select/selectOne
	lg.getObject = function(id) {
		return this.selectOne({id: id});
	};

	// Create a model object of the correct sub-class given its attributes
	lg.createObject = function(atts) {
		var cls = this.typeToClass(atts.type);
		var obj = this.isObject(lg[cls]) ? new lg[cls](atts) : new lg.GlobalObject(atts);
		for(var i in atts) obj[i] = atts[i];
		obj.id = atts.id;
		return obj;
	};

	// Ensure this object is of the appropriate model sub-class
	lg.upgradeObject = function(obj) {
		var upg = this.createObject(obj.attributes);
		for(var i in upg) obj[i] = upg[i];
	};

	// Return the objects that match the passed criteria
	lg.select = function(cond) {
		var objects = lg.ligminchaGlobal.toArray();
		var list = [];
		for(var i in objects) {
			if(this.match(objects[i], cond)) list.push(objects[i]);
		}
		return list;
	};

	// Return the single object that matches the passed criteria (raises warning if more than one match)
	lg.selectOne = function(cond) {
		var list = this.select(cond);
		if(list.length == 0) return false;
		if(list.length > 1) console.log('selectOne produced more than one result, first picked');
		return list[0];
	};

	// Return whether the passed object matches the passed criteria
	// TODO: this wouldn't be needed if we were maintaining parameter indexes for the object collection
	// TODO: this should allow OR like the PHP equivalents do
	lg.match = function(obj, cond) {
		var match = true;
		for(var i in cond) {
			if(obj.attributes[i] != cond[i]) match = false;
		}
		return match;
	};

	// Delete any objects that have pass thier expiry time
	lg.expire = function() {
		var objects = lg.ligminchaGlobal.toArray();
		var ts = this.timestamp();
		for(var i in objects) {
			var obj = objects[i];
			if(obj.attributes.expire > 0 && obj.attributes.expire < ts) {
				console.log('Object ' + obj.attributes.id.short() + ' expired');
				lg.ligminchaGlobal.remove(obj);
			}
		}
	};

	// Receive sync-object queue from a remote server (The JS version of the PHP LigminchaGlobalDistributed::recvQueue)
	lg.recvQueue = function(queue) {
		console.log('Queue received');
		var ip = queue.shift();
		var origin = queue.shift();
		var session = queue.shift();

		// Process each of the sync objects (this may lead to further re-routing sync objects being made)
		for(var i in queue) {
			this.process( queue[i].tag, queue[i].data, origin );
		}
	};

	// Encodes data into JSON format if it's an object
	lg.encodeData = function(json) {
		return this.isObject(json) ? JSON.stringify(json) : json;
	};

	// Decodes data if it's JSON encoded
	lg.decodeData = function(data) {
		return (data.charAt(0) === '{' || data.charAt(0) === '[') ? JSON.parse(data) : data;
	};

	// Process an inbound sync object (JS version of LigminchaGlobalSync::process)
	lg.process = function(crud, fields, origin) {
		if(crud == 'U') {
			console.log('Update received for ' + fields.id);
			var obj = lg.getObject(fields.id);
			if(obj) {
				console.log('Updating ' + fields.id);
				obj.update(fields);
			} else {
				console.log('Creating ' + fields.id);
				lg.ligminchaGlobal.create(fields);
				if(fields.type == LG_SESSION) this.updateChatMenu();
				if(fields.type == LG_LOG && fields.tag == 'Info') this.newInfo(fields.data);
			}
		} else if(crud == 'D') {
			console.log('Delete received');
			console.log(fields);
			lg.del(fields);
		} else console.log('Unknown CRUD method "' + crud + '"');
	};

	// Delete the objects that match the passed criteria
	lg.del = function(cond) {
		var list = this.select(cond);
		var sessions = false;
		for(var i in list) {
			if(list[i].type == LG_SESSION) sessions = true;
			console.log('Deleting: ' + list[i].id);
			this.ligminchaGlobal.remove(list[i]);
		}
		if(sessions) lg.updateChatMenu();
	};

	// Hash that is compatible with the server-side
	lg.hash = function(s) {
		var h = CryptoJS.SHA1(s) + "";
		return h.toUpperCase();
	};

	// Generate a new globally unique ID
	lg.uuid = function() {
		return this.hash(Math.random() + "");
	};

	// Return a unix style timestamp
	lg.timestamp = function() {
		var date = new Date;
		return date.getTime()/1000;
	};

	// Convert a class constant into a class name
	lg.typeToClass = function(type) {
		if(type in this.classes) return this.classes[type];
		else console.log('No class for unknown type: ' + type);
		return 'GlobalObject';
	};

	// Return whether the passed item is an object or not
	lg.isObject = function isObject(item) {
		return item === Object(item);
	};

	// Per-second ticker function
	lg.ticker = function() {
		setTimeout(lg.ticker, 1000);
		lg.expire();
	};

}(jQuery, window.lg));
