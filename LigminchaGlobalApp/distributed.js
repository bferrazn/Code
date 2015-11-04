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
var lg = {
	classes: [0, 'Log', 'Server', 'User', 'Session', 'Sync', 'Version', 'Database'],
};

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

// Render a select list of global objects from the passed query
// - atts is the attributes to give the select element
// - cur is the current value to be selected if any
// - key is the attribute of the object to use as the displayed value (tag by default)
lg.selectList = function(query, atts, cur, key) {
	if(key === undefined) key = 'tag';
	var html = '<select';
	for(var i in atts) html += ' ' + i + '="' + atts[i] + '"';
	html += '>'
	var opts = this.select(query);
	for(var i in opts) {
		var optatts = opts[i].attributes;
		var opt = (key in optatts) ? optatts[key] : optatts.data[key];
		var s = opt == cur ? ' selected' : '';
		html += '<option value="' + optatts.id + '"' + s + '>' + opt + '</option>';
	}
	html += '</select>';
	return html;
};

// Get a list of the tags fro Github
lg.tagList = function() {
	var html = '';
	for(var i in mw.config.get('tags')) html += '<option>' + i + '</option>';
	return html;
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
			console.log(obj.attributes.id + ' expired');
			lg.ligminchaGlobal.remove(obj);
		}
	}
};

// Receive sync-object queue from a remote server (The JS version of the PHP LigminchaGlobalDistributed::recvQueue)
lg.recvQueue = function(queue) {
	var ip = queue.shift();
	var origin = queue.shift();
	var session = queue.shift();

	// Process each of the sync objects (this may lead to further re-routing sync objects being made)
	for( var i in queue ) {
		this.process( queue[i].tag, queue[i].data, origin );
	}
};

// Send an object (the JS side doesn't do sendQueue since it's a real-time connection, but still needs to be compatible data)
lg.sendObject = function(obj) {
	var master = lg.Server.getMaster();

	// Create an LG_SYNC object for the object we want to send
	var sync = {
		type: LG_SYNC,
		ref1: master.id,
		ref2: obj.id,
		data: obj.attributes,
		tag: 'U',
	};

	// Send a recvQueue format array with the sync object in it
	// - we use the WebSocket client ID as the session ID so the WebSocket daemon doesn't bounce the message back to us
	$.ajax({
		type: 'POST',
		url: '/index.php',
		data: {sync: [0, 0, mw.data.wsClientID, sync]},
		dataType: 'text',
		success: function(text) {
			if(text != LG_SUCCESS) console.log('Sync post to master not ok: ' + text);
		}
	});
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
	for( var i in list ) {
		console.log('Deleting: ' + list[i].id);
		lg.ligminchaGlobal.remove(list[i]);
	}
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
	if(type in lg.classes) return lg.classes[type];
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

// Load a template and precompile ready for use
// - template is the name of the template to load (/templates/NAME.html will be loaded)
// - args is the object containing the parameters to populate the template with
// - target is a jQuery selector string, a jQuery element or a function
lg.template = function(template, args, target) {

	// Create a list for the templates if not already existent
	if(!('templates' in this)) this.templates = [];

	// If the template is already loaded and compiled, process the final result immediately
	if(template in this.templates) this.templateRender(this.templates[template](args), target);

	// Otherwise load the template, and when it's loaded compile it and return the result
	else {
		this.templateRender('<div class="loading"></div>', target);
		$.ajax({
			type: 'GET',
			url: '/templates/' + template + '.html',
			context: this,
			dataType: 'html',
			success: function(html) {

				// Compile the template
				this.templates[template] = _.template(html);

				// Process the template with our args and send through the callback
				this.templateRender(this.templates[template](args), target);
			}
		});
	}
};
lg.templateRender = function(html, target) {
	var type = typeof target;
	if(type == 'string') $(target).html(html);
	else if(type == 'object') target.html(html);
	else if(type == 'function') target(html);
	else console.log('lg.template can\'t process type "' + type + '"');
};
