/**
 * This contains most of the equivalent functionality to the LigminchaGlobalDistributed PHP class
 */

'use strict';

// LigminchaGlobal is a Backbone Collection class for all the distributed objects locally available
lg.LigminchaGlobal = Backbone.Collection.extend({
	model: lg.GlobalObject,
	url: 'localhost',
	//localStorage: new Store("ligminchaGlobal")
});

// Instance of the Collection
lg.ligminchaGlobal = new lg.LigminchaGlobal();

// Return the reference to an objects model given its GUID
// TODO: we should maintain indexes for the main parameters for this method and select/selectOne
lg.getObject = function(id) {
	return this.selectOne({id: id});
};

// Return the objects that match the passed criteria
lg.select = function(cond) {
	var objects = lg.ligminchaGlobal.toArray();
	var list = [];
	for(var i in objects) {
		if(this.matches(objects[i], cond)) list.push(objects[i]);
	}
	return list;
};

// Return the single object that matches the passed criteria (raises warning if more than one match)
lg.selectOne = function(cond) {
	var list = this.select(cond);
	if(list.length == 0) return false;
	if(list.length > 0) console.log('selectOne produced more than one result, first picked');
	return list[0];
};

// Return whether the passed object matches the passed criteria
// TODO: this wouldn't be needed if we were maintaining parameter indexes for the object collection
// TODO: this should allow OR like the PHP equivalents do
lg.match = function(obj, cond) {
	var matches = true;
	for( var i in cond ) {
		if(obj[i] != cond[i]) match = false;
	}
	return matches;
};

// Receive sync-object queue from a remote server (The JS version of the PHP LigminchaGlobalDistributed::recvQueue)
lg.recvQueue = function(queue) {
	var origin = queue.shift();
	var session = queue.shift();

	// Process each of the sync objects (this may lead to further re-routing sync objects being made)
	for( var i in queue ) {
		this.process( queue[i].tag, queue[i].data, origin );
	}
};

// Send the list of sync-objects (The JS version of the PHP LigminchaGlobalDistributed::sendQueue)
lg.sendQueue = function(queue) {
};

// Encodes data into the format requred by distributed.php
lg.encodeData = function(json) {
	return JSON.stringify(json);
};

// Decodes distributed queue data
lg.decodeData = function(data) {
	return JSON.parse(data);
};

// Process an inbound sync object (JS version of LigminchaGlobalSync::process)
lg.process = function(crud, fields, origin) {
	if(crud == 'U') {
		console.log('Update received for ' + fields.id);
		lg.getObject(fields.id).update(fields);
	} else if(crud == 'D') {
		console.log('Delete received');
		lg.del(fields);
	} else console.log('Unknown CRUD method "' + crud + '"');
};

// Delete the objects that match the passed criteria
lg.del = function(cond) {
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