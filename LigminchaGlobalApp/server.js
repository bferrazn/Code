'use strict';

lg.Server = lg.GlobalObject.extend({

	// Return the current server object
	getCurrent: function() {
	},

	// Return the master server object
	getMaster: function() {
	},

	// Render server-sprcific properties
	properties: function() {
		return 'foo';
	},
});

// Renders individual server item with contained users and sessions
lg.ServerView = Backbone.View.extend({
	tagName: 'li',
	render: function() {
		var server = this.model.attributes;
		var html = '<span id="' + server.id + '" href="http://' + server.tag + '">' + server.data.name + '</span>';
		var users = lg.select({type: LG_USER, ref1: server.id});
		if(users) {
			html += '<ul>';
			for( var i in users ) {
				var user = users[i].attributes;
				var sessions = lg.select({type: LG_SESSION, owner: user.id});
				var col = sessions.length ? 'green' : 'red';
				html += '<li style="color:' + col + '"><span id="' + user.id + '">' + user.data.username + ' (' + user.id.substr(0,5) + ')</span></li>';
				if(sessions.length) {
					html += '<ul>';
					for( var j in sessions ) {
						var session = sessions[j].attributes;
						html += '<li>' + session.tag + ' (' + session.id.substr(0,5) + ')</li>';
					}
					html += '</ul>';
				}
			}
			html += '</ul>';
		}
		this.$el.html(html);
		$('span', this.$el).css('cursor','pointer').click(function() {
			var id = $(this).attr('id');
			if(id) new lg.ObjectView({model: lg.getObject(id)});
		});
		return this; // enable chained calls
	},
	initialize: function(){
		this.model.on('change', this.render, this);
		this.model.on('destroy', this.remove, this); // remove: Convenience Backbone's function for removing the view from the DOM.
	},
	events: {
		'click .destroy' : 'destroy'
	},
	destroy: function(){
		this.model.destroy();
	}
});
